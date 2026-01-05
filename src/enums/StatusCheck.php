<?php

namespace zaengle\phonehome\enums;

enum StatusCheck: string
{
    case CRITICAL = 'critical';
    case WARNING = 'warning';
    case OK = 'ok';
}
