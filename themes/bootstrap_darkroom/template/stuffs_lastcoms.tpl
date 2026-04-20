{combine_css path='themes/bootstrap_darkroom/css/stuffs_lastcoms.css' order=-10}
{if !empty($block.MAX_WIDTH) or !empty($block.MAX_HEIGHT) or !empty($block.NB_COMMENTS_LINE)}
    {html_head}
    
    {/html_head}
{/if}

<div id="comments">
    {assign var=comments value=$block.comments}
    {assign var='derivative_params' value=$block.derivative_params}
    {include file='comment_list.tpl'}
</div>