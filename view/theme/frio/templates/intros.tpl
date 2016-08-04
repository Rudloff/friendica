
<div class="intro-wrapper media" id="intro-{{$contact_id}}" >

	{{* Contact Photo *}}
	<div class="intro-photo-wrapper dropdown pull-left" >
		<img id="photo-{{$contact_id}}" class="intro-photo media-object" src="{{$photo}}" title="{{$fullname|escape:'html'}}" alt="{{$fullname|escape:'html'}}" />
	</div>

	<div class="media-body">
		{{* The intro actions like approve, ignore, discard intro*}}
		<div class="intro-actions pull-right nav-pills preferences hidden-xs">
			<button class="btn-link intro-action-link" onclick="addElmToModal('#intro-approve-wrapper-{{$contact_id}}')"><i class="fa fa-check" aria-hidden="true"></i></button>
			
			<form class="intro-form" action="notifications/{{$intro_id}}" method="post">
				<button class="btn-link intro-submit-ignore intro-action-link" type="submit" name="submit" value="{{$ignore|escape:'html'}}"><i class="fa fa-ban" aria-hidden="true"></i></button>
				<button class="btn-link intro-submit-discard intro-action-link" type="submit" name="submit" value="{{$discard|escape:'html'}}"><i class="fa fa-trash-o" aria-hidden="true"></i></button>
			</form>
		</div>
		<div class='intro-enty-name'><h4 class="media-heading"><a href="{{$zrl}}">{{$fullname}}</a></h4></div>
		<div class="intro-desc"><span class="intro-desc-label">{{$str_notifytype}}</span>{{$notify_type}}</div>

		{{* Additional information of the contact *}}
		<div class="intro-url"><span class="intro-url-label">{{$lbl_url}}:&nbsp;</span><a href="{{$zrl}}">{{$url}}</a></div>
		{{if $network}}<div class="intro-network"><span class="intro-network-label">{{$lbl_network}}</span>&nbsp;{{$network}}</div>{{/if}}
		{{if $location}}<div class="intro-location"><span class="intro-location-label">{{$lbl_location}}</span>&nbsp;{{$location}}</div>{{/if}}
		{{if $gender}}<div class="intro-gender"><span class="intro-gender-label">{{$lbl_gender}}</span>&nbsp;{{$gender}}</div>{{/if}}
		{{if $keywords}}<div class="intro-keywords"><span class="intro-keywords-label">{{$lbl_keywords}}</span>&nbsp;{{$keywords}}</div>{{/if}}
		{{if $about}}<div class="intro-about"><span class="intro-about-label">{{$lbl_about}}</span>&nbsp;{{$about}}</div>{{/if}}
		<div class="intro-knowyou"><span class="intro-knowyou-label">{{$lbl_knowyou}}</span>{{$knowyou}}</div>
		<div class="intro-note" id="intro-note-{{$contact_id}}">{{$note}}</div>

		{{* This sections contains special settings for contact approval. We hide it by default and load this section in
		a bootstrap modal in the case of approval *}}
		<div id="intro-approve-wrapper-{{$contact_id}}" style="display: none;">

			<h3 class="heading">{{$fullname}}{{if $addr}}&nbsp;({{$addr}}){{/if}}</h3>
			<form class="intro-approve-form" action="dfrn_confirm" method="post">
				{{include file="field_checkbox.tpl" field=$hidden}}
				{{include file="field_checkbox.tpl" field=$activity}}
				<input type="hidden" name="dfrn_id" value="{{$dfrn_id}}" >
				<input type="hidden" name="intro_id" value="{{$intro_id}}" >
				<input type="hidden" name="contact_id" value="{{$contact_id}}" >

				{{$dfrn_text}}

				<div class="pull-right">
					<button class="btn btn-default intro-submit-approve" type="submit" name="submit" value="{{$approve|escape:'html'}}">{{$approve|escape:'html'}}</button>
				</div>
				<div class="clear"></div>
			</form>
		</div>
	</div>
</div>
<div class="intro-end"></div>