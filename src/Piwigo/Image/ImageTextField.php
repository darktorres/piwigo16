<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * {@see ImageRepository::updateTextFieldForImages()}'s `$field`
 * parameter, enumerated -- the only 3 real values any caller ever passes
 * (`Admin\BatchManagerGlobalPageRenderer`'s own author/title/date
 * fields).
 */
enum ImageTextField
{
    case Author;
    case Name;
    case DateCreation;
}
