<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyDecisionKind: string
{
    case ORDINARY = 'ordinary';
    case ELECTION_OR_REMOVAL = 'election_or_removal';
    case HOUSE_RULES = 'house_rules';
    case MAJOR_REPAIR_OR_RENOVATION = 'major_repair_or_renovation';
    case EU_OR_PUBLIC_FUNDING = 'eu_or_public_funding';
    case USE_OR_CHANGE_OF_COMMON_PARTS = 'use_or_change_of_common_parts';
    case RIGHT_OF_USE_OR_BUILDING_RIGHT = 'right_of_use_or_building_right';
    case MANAGEMENT_MAINTENANCE_COST_DISTRIBUTION = 'management_maintenance_cost_distribution';
    case ASSOCIATION_RELATED = 'association_related';
    case OTHER_REQUIRES_REVIEW = 'other_requires_review';
}
