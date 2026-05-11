<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

enum WidgetCategory: string
{
    case Numbers  = 'numbers';
    case Lists    = 'lists';
    case Charts   = 'charts';
    case Actions  = 'actions';
    case Activity = 'activity';
    case Platform = 'platform';
}
