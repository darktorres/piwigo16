{if isset($errors) }
    <div class="container{if $theme_config->fluid_width}-fluid{/if}">
        {foreach $errors as $error}
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                {$error}
            </div>
        {/foreach}
    </div>
{/if}

{if not empty($infos)}
    <div class="container{if $theme_config->fluid_width}-fluid{/if}">
        {foreach $infos as $info}
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                {$info}
            </div>
        {/foreach}
    </div>
{/if}