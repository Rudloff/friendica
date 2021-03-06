<?php
/**
 * @file index.php
 * Friendica
 */

/**
 * Bootstrap the application
 */

use Friendica\App;
use Friendica\Content\Nav;
use Friendica\Core\Addon;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\Session;
use Friendica\Core\System;
use Friendica\Core\Theme;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Model\Profile;
use Friendica\Module\Login;

require_once 'boot.php';

$a = new App(__DIR__);

// We assume that the index.php is called by a frontend process
// The value is set to "true" by default in boot.php
$a->backend = false;

/**
 * Try to open the database;
 */

require_once "include/dba.php";

// Missing DB connection: ERROR
if ($a->getMode()->has(App\Mode::LOCALCONFIGPRESENT) && !$a->getMode()->has(App\Mode::DBAVAILABLE)) {
	System::httpExit(500, ['title' => 'Error 500 - Internal Server Error', 'description' => 'Apologies but the website is unavailable at the moment.']);
}

// Max Load Average reached: ERROR
if ($a->isMaxProcessesReached() || $a->isMaxLoadReached()) {
	header('Retry-After: 120');
	header('Refresh: 120; url=' . System::baseUrl() . "/" . $a->query_string);

	System::httpExit(503, ['title' => 'Error 503 - Service Temporarily Unavailable', 'description' => 'System is currently overloaded. Please try again later.']);
}

if (!$a->getMode()->isInstall()) {
	if (Config::get('system', 'force_ssl') && ($a->get_scheme() == "http")
		&& (intval(Config::get('system', 'ssl_policy')) == SSL_POLICY_FULL)
		&& (substr(System::baseUrl(), 0, 8) == "https://")
		&& ($_SERVER['REQUEST_METHOD'] == 'GET')) {
		header("HTTP/1.1 302 Moved Temporarily");
		header("Location: " . System::baseUrl() . "/" . $a->query_string);
		exit();
	}

	Config::init();
	Session::init();
	Addon::loadHooks();
	Addon::callHooks('init_1');
}

$lang = L10n::getBrowserLanguage();

L10n::loadTranslationTable($lang);

/**
 * Important stuff we always need to do.
 *
 * The order of these may be important so use caution if you think they're all
 * intertwingled with no logical order and decide to sort it out. Some of the
 * dependencies have changed, but at least at one time in the recent past - the
 * order was critical to everything working properly
 */

// Exclude the backend processes from the session management
if (!$a->is_backend()) {
	$stamp1 = microtime(true);
	session_start();
	$a->save_timestamp($stamp1, "parser");
} else {
	$_SESSION = [];
	Worker::executeIfIdle();
}

/**
 * Language was set earlier, but we can over-ride it in the session.
 * We have to do it here because the session was just now opened.
 */
if (!empty($_SESSION['authenticated']) && empty($_SESSION['language'])) {
	$_SESSION['language'] = $lang;
	// we haven't loaded user data yet, but we need user language
	if (!empty($_SESSION['uid'])) {
		$user = DBA::selectFirst('user', ['language'], ['uid' => $_SESSION['uid']]);
		if (DBA::isResult($user)) {
			$_SESSION['language'] = $user['language'];
		}
	}
}

if (!empty($_SESSION['language']) && $_SESSION['language'] !== $lang) {
	$lang = $_SESSION['language'];
	L10n::loadTranslationTable($lang);
}

if (!empty($_GET['zrl']) && $a->getMode()->isNormal()) {
	$a->query_string = Profile::stripZrls($a->query_string);
	if (!local_user()) {
		// Only continue when the given profile link seems valid
		// Valid profile links contain a path with "/profile/" and no query parameters
		if ((parse_url($_GET['zrl'], PHP_URL_QUERY) == "") &&
			strstr(parse_url($_GET['zrl'], PHP_URL_PATH), "/profile/")) {
			if (defaults($_SESSION, "visitor_home", "") != $_GET["zrl"]) {
				$_SESSION['my_url'] = $_GET['zrl'];
				$_SESSION['authenticated'] = 0;
			}
			Profile::zrlInit($a);
		} else {
			// Someone came with an invalid parameter, maybe as a DDoS attempt
			// We simply stop processing here
			logger("Invalid ZRL parameter " . $_GET['zrl'], LOGGER_DEBUG);
			header('HTTP/1.1 403 Forbidden');
			echo "<h1>403 Forbidden</h1>";
			exit();
		}
	}
}

if (!empty($_GET['owt']) && $a->getMode()->isNormal()) {
	$token = $_GET['owt'];
	$a->query_string = Profile::stripQueryParam($a->query_string, 'owt');
	Profile::openWebAuthInit($token);
}

/**
 * For Mozilla auth manager - still needs sorting, and this might conflict with LRDD header.
 * Apache/PHP lumps the Link: headers into one - and other services might not be able to parse it
 * this way. There's a PHP flag to link the headers because by default this will over-write any other
 * link header.
 *
 * What we really need to do is output the raw headers ourselves so we can keep them separate.
 */

// header('Link: <' . System::baseUrl() . '/amcd>; rel="acct-mgmt";');

Login::sessionAuth();

if (empty($_SESSION['authenticated'])) {
	header('X-Account-Management-Status: none');
}

$_SESSION['sysmsg']       = defaults($_SESSION, 'sysmsg'      , []);
$_SESSION['sysmsg_info']  = defaults($_SESSION, 'sysmsg_info' , []);
$_SESSION['last_updated'] = defaults($_SESSION, 'last_updated', []);

/*
 * check_config() is responsible for running update scripts. These automatically
 * update the DB schema whenever we push a new one out. It also checks to see if
 * any addons have been added or removed and reacts accordingly.
 */

// in install mode, any url loads install module
// but we need "view" module for stylesheet
if ($a->getMode()->isInstall() && $a->module != 'view') {
	$a->module = 'install';
} elseif (!$a->getMode()->has(App\Mode::MAINTENANCEDISABLED) && $a->module != 'view') {
	$a->module = 'maintenance';
} else {
	check_url($a);
	check_db(false);
	Addon::check();
}

Nav::setSelected('nothing');

//Don't populate apps_menu if apps are private
$privateapps = Config::get('config', 'private_addons');
if ((local_user()) || (! $privateapps === "1")) {
	$arr = ['app_menu' => $a->apps];

	Addon::callHooks('app_menu', $arr);

	$a->apps = $arr['app_menu'];
}

/**
 * We have already parsed the server path into $a->argc and $a->argv
 *
 * $a->argv[0] is our module name. We will load the file mod/{$a->argv[0]}.php
 * and use it for handling our URL request.
 * The module file contains a few functions that we call in various circumstances
 * and in the following order:
 *
 * "module"_init
 * "module"_post (only called if there are $_POST variables)
 * "module"_afterpost
 * "module"_content - the string return of this function contains our page body
 *
 * Modules which emit other serialisations besides HTML (XML,JSON, etc.) should do
 * so within the module init and/or post functions and then invoke killme() to terminate
 * further processing.
 */
if (strlen($a->module)) {

	/**
	 * We will always have a module name.
	 * First see if we have an addon which is masquerading as a module.
	 */

	// Compatibility with the Android Diaspora client
	if ($a->module == 'stream') {
		goaway('network?f=&order=post');
	}

	if ($a->module == 'conversations') {
		goaway('message');
	}

	if ($a->module == 'commented') {
		goaway('network?f=&order=comment');
	}

	if ($a->module == 'liked') {
		goaway('network?f=&order=comment');
	}

	if ($a->module == 'activity') {
		goaway('network/?f=&conv=1');
	}

	if (($a->module == 'status_messages') && ($a->cmd == 'status_messages/new')) {
		goaway('bookmarklet');
	}

	if (($a->module == 'user') && ($a->cmd == 'user/edit')) {
		goaway('settings');
	}

	if (($a->module == 'tag_followings') && ($a->cmd == 'tag_followings/manage')) {
		goaway('search');
	}

	// Compatibility with the Firefox App
	if (($a->module == "users") && ($a->cmd == "users/sign_in")) {
		$a->module = "login";
	}

	$privateapps = Config::get('config', 'private_addons');

	if (is_array($a->addons) && in_array($a->module, $a->addons) && file_exists("addon/{$a->module}/{$a->module}.php")) {
		//Check if module is an app and if public access to apps is allowed or not
		if ((!local_user()) && Addon::isApp($a->module) && $privateapps === "1") {
			info(L10n::t("You must be logged in to use addons. "));
		} else {
			include_once "addon/{$a->module}/{$a->module}.php";
			if (function_exists($a->module . '_module')) {
				$a->module_loaded = true;
			}
		}
	}

	// Controller class routing
	if (! $a->module_loaded && class_exists('Friendica\\Module\\' . ucfirst($a->module))) {
		$a->module_class = 'Friendica\\Module\\' . ucfirst($a->module);
		$a->module_loaded = true;
	}

	/**
	 * If not, next look for a 'standard' program module in the 'mod' directory
	 */

	if (! $a->module_loaded && file_exists("mod/{$a->module}.php")) {
		include_once "mod/{$a->module}.php";
		$a->module_loaded = true;
	}

	/**
	 * The URL provided does not resolve to a valid module.
	 *
	 * On Dreamhost sites, quite often things go wrong for no apparent reason and they send us to '/internal_error.html'.
	 * We don't like doing this, but as it occasionally accounts for 10-20% or more of all site traffic -
	 * we are going to trap this and redirect back to the requested page. As long as you don't have a critical error on your page
	 * this will often succeed and eventually do the right thing.
	 *
	 * Otherwise we are going to emit a 404 not found.
	 */

	if (! $a->module_loaded) {
		// Stupid browser tried to pre-fetch our Javascript img template. Don't log the event or return anything - just quietly exit.
		if (!empty($_SERVER['QUERY_STRING']) && preg_match('/{[0-9]}/', $_SERVER['QUERY_STRING']) !== 0) {
			killme();
		}

		if (!empty($_SERVER['QUERY_STRING']) && ($_SERVER['QUERY_STRING'] === 'q=internal_error.html') && isset($dreamhost_error_hack)) {
			logger('index.php: dreamhost_error_hack invoked. Original URI =' . $_SERVER['REQUEST_URI']);
			goaway(System::baseUrl() . $_SERVER['REQUEST_URI']);
		}

		logger('index.php: page not found: ' . $_SERVER['REQUEST_URI'] . ' ADDRESS: ' . $_SERVER['REMOTE_ADDR'] . ' QUERY: ' . $_SERVER['QUERY_STRING'], LOGGER_DEBUG);
		header($_SERVER["SERVER_PROTOCOL"] . ' 404 ' . L10n::t('Not Found'));
		$tpl = get_markup_template("404.tpl");
		$a->page['content'] = replace_macros($tpl, [
			'$message' =>  L10n::t('Page not found.')
		]);
	}
}

/**
 * Load current theme info
 */
$theme_info_file = 'view/theme/' . $a->getCurrentTheme() . '/theme.php';
if (file_exists($theme_info_file)) {
	require_once $theme_info_file;
}


/* initialise content region */

if ($a->getMode()->isNormal()) {
	Addon::callHooks('page_content_top', $a->page['content']);
}

/**
 * Call module functions
 */

if ($a->module_loaded) {
	$a->page['page_title'] = $a->module;
	$placeholder = '';

	Addon::callHooks($a->module . '_mod_init', $placeholder);

	if ($a->module_class) {
		call_user_func([$a->module_class, 'init']);
	} else if (function_exists($a->module . '_init')) {
		$func = $a->module . '_init';
		$func($a);
	}

	// "rawContent" is especially meant for technical endpoints.
	// This endpoint doesn't need any theme initialization or other comparable stuff.
	if (!$a->error && $a->module_class) {
		call_user_func([$a->module_class, 'rawContent']);
	}

	if (function_exists(str_replace('-', '_', $a->getCurrentTheme()) . '_init')) {
		$func = str_replace('-', '_', $a->getCurrentTheme()) . '_init';
		$func($a);
	}

	if (! $a->error && $_SERVER['REQUEST_METHOD'] === 'POST') {
		Addon::callHooks($a->module . '_mod_post', $_POST);
		if ($a->module_class) {
			call_user_func([$a->module_class, 'post']);
		} else if (function_exists($a->module . '_post')) {
			$func = $a->module . '_post';
			$func($a);
		}
	}

	if (! $a->error) {
		Addon::callHooks($a->module . '_mod_afterpost', $placeholder);
		if ($a->module_class) {
			call_user_func([$a->module_class, 'afterpost']);
		} else if (function_exists($a->module . '_afterpost')) {
			$func = $a->module . '_afterpost';
			$func($a);
		}
	}

	if (! $a->error) {
		$arr = ['content' => $a->page['content']];
		Addon::callHooks($a->module . '_mod_content', $arr);
		$a->page['content'] = $arr['content'];
		if ($a->module_class) {
			$arr = ['content' => call_user_func([$a->module_class, 'content'])];
		} else if (function_exists($a->module . '_content')) {
			$func = $a->module . '_content';
			$arr = ['content' => $func($a)];
		}
		Addon::callHooks($a->module . '_mod_aftercontent', $arr);
		$a->page['content'] .= $arr['content'];
	}

	if (function_exists(str_replace('-', '_', $a->getCurrentTheme()) . '_content_loaded')) {
		$func = str_replace('-', '_', $a->getCurrentTheme()) . '_content_loaded';
		$func($a);
	}
}

/*
 * Create the page head after setting the language
 * and getting any auth credentials.
 *
 * Moved init_pagehead() and init_page_end() to after
 * all the module functions have executed so that all
 * theme choices made by the modules can take effect.
 */

$a->initHead();

/*
 * Build the page ending -- this is stuff that goes right before
 * the closing </body> tag
 */
$a->initFooter();

/*
 * now that we've been through the module content, see if the page reported
 * a permission problem and if so, a 403 response would seem to be in order.
 */
if (stristr(implode("", $_SESSION['sysmsg']), L10n::t('Permission denied'))) {
	header($_SERVER["SERVER_PROTOCOL"] . ' 403 ' . L10n::t('Permission denied.'));
}

/*
 * Report anything which needs to be communicated in the notification area (before the main body)
 */
Addon::callHooks('page_end', $a->page['content']);

/*
 * Add the navigation (menu) template
 */
if ($a->module != 'install' && $a->module != 'maintenance') {
	Nav::build($a);
}

/**
 * Build the page - now that we have all the components
 */
if (isset($_GET["mode"]) && (($_GET["mode"] == "raw") || ($_GET["mode"] == "minimal"))) {
	$doc = new DOMDocument();

	$target = new DOMDocument();
	$target->loadXML("<root></root>");

	$content = mb_convert_encoding($a->page["content"], 'HTML-ENTITIES', "UTF-8");

	/// @TODO one day, kill those error-surpressing @ stuff, or PHP should ban it
	@$doc->loadHTML($content);

	$xpath = new DOMXPath($doc);

	$list = $xpath->query("//*[contains(@id,'tread-wrapper-')]");  /* */

	foreach ($list as $item) {
		$item = $target->importNode($item, true);

		// And then append it to the target
		$target->documentElement->appendChild($item);
	}
}

if (isset($_GET["mode"]) && ($_GET["mode"] == "raw")) {
	header("Content-type: text/html; charset=utf-8");

	echo substr($target->saveHTML(), 6, -8);

	exit();
}

$page    = $a->page;
$profile = $a->profile;

header("X-Friendica-Version: " . FRIENDICA_VERSION);
header("Content-type: text/html; charset=utf-8");

if (Config::get('system', 'hsts') && (Config::get('system', 'ssl_policy') == SSL_POLICY_FULL)) {
	header("Strict-Transport-Security: max-age=31536000");
}

// Some security stuff
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('X-Permitted-Cross-Domain-Policies: none');
header('X-Frame-Options: sameorigin');

// Things like embedded OSM maps don't work, when this is enabled
// header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' https: data:; media-src 'self' https:; child-src 'self' https:; object-src 'none'");

/*
 * We use $_GET["mode"] for special page templates. So we will check if we have
 * to load another page template than the default one.
 * The page templates are located in /view/php/ or in the theme directory.
 */
if (isset($_GET["mode"])) {
	$template = Theme::getPathForFile($_GET["mode"] . '.php');
}

// If there is no page template use the default page template
if (empty($template)) {
	$template = Theme::getPathForFile("default.php");
}

/// @TODO Looks unsafe (remote-inclusion), is maybe not but Theme::getPathForFile() uses file_exists() but does not escape anything
require_once $template;
