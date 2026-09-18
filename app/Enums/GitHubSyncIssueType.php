<?php

namespace App\Enums;

enum GitHubSyncIssueType: string
{
    case RepositoryDeleted = 'repository_deleted';
    case RepositoryRenamed = 'repository_renamed';
    case TeamDeleted = 'team_deleted';
    case TeamEdited = 'team_edited';
    case TeamRepositoryAccessRemoved = 'team_repository_access_removed';
    case MembershipAdded = 'membership_added';
    case MembershipRemoved = 'membership_removed';
}
