<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Item 15 Sub-item D: {@see ImageRepository::updateTextFieldForImages()}'s
 * `$field` parameter, enumerated -- the only 3 real values any caller ever
 * passes (`Admin\BatchManagerGlobalPageRenderer`'s own author/title/date
 * fields), confirmed via a fresh grep before converting.
 */
enum ImageTextField
{
    case Author;
    case Name;
    case DateCreation;
}
