{combine_css path='node_modules/cropperjs/dist/cropper.min.css'}

{if $vite_picture_coi}
<script type="module" src="admin/themes/default/js/dist/{$vite_picture_coi}"></script>
{/if}

<form method="post">

	<fieldset>
		<legend>{'Photo sizes with crop'|translate}</legend>
		{foreach $cropped_derivatives as $deriv}
			<img src="{$deriv.U_IMG}" alt="{$ALT}" {$deriv.HTM_SIZE}>
		{/foreach}
	</fieldset>

	<fieldset>
		<legend>{'Center of interest'|translate}</legend>
		<p style="margin:0 0 10px 0;padding:0;">
			{'The center of interest is the most meaningful zone in the photo.'|translate}
			{'For photo sizes with crop, such as "Square", Piwigo will do its best to include the center of interest.'|translate}
			{'By default, the center of interest is placed in the middle of the photo.'|translate}
			{'Select a zone with your mouse to define a new center of interest.'|translate}
		</p>
		<input type="hidden" id="l" name="l" value="{if isset($coi)}{$coi.l}{/if}">
		<input type="hidden" id="t" name="t" value="{if isset($coi)}{$coi.t}{/if}">
		<input type="hidden" id="r" name="r" value="{if isset($coi)}{$coi.r}{/if}">
		<input type="hidden" id="b" name="b" value="{if isset($coi)}{$coi.b}{/if}">

		<div id="jcrop-container" style="max-width:500px;">
			<img id="jcrop" src="{$U_IMG}" alt="{$ALT}" style="display:block;max-width:100%;" {if isset($coi)}data-coi='{$coi|json_encode}'{/if}>
		</div>

		<p>
			<button type="button" id="jcrop-clear">{'Clear center of interest'|translate}</button>
		</p>

		<p>
			<input type="submit" name="submit" value="{'Submit'|translate}">
		</p>
	</fieldset>
</form>

