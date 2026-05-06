<?php

declare(strict_types=1);

namespace Piwigo\Search;

const QST_QUOTED         = 0x01;
const QST_NOT            = 0x02;
const QST_OR             = 0x04;
const QST_WILDCARD_BEGIN = 0x08;
const QST_WILDCARD_END   = 0x10;
const QST_WILDCARD       = QST_WILDCARD_BEGIN | QST_WILDCARD_END;
const QST_BREAK          = 0x20;
