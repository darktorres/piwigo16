{include file='infos_errors.tpl'}
<div>

	{if isset($comments)}
		{include file='comment_list.tpl' comment_derivative_params=$comment_derivative_params}
	{/if}

</div> <!-- content -->