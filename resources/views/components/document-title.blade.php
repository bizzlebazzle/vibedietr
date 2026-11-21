@props(['header' => null, 'title' => null])
@php
    $heading = $title ?? $header;
    $topic = $heading !== null
        ? html_entity_decode(trim(strip_tags((string) $heading)), ENT_QUOTES, 'UTF-8')
        : match (request()->route()?->getName()) {
            'login' => 'Log in',
            'register' => 'Create account',
            'password.request' => 'Reset your password',
            'password.reset' => 'Choose a new password',
            'password.confirm' => 'Confirm your password',
            'verification.notice' => 'Verify your email',
            'recipes.index' => match (request()->query('view')) {
                'mine' => 'My recipes',
                'drafts' => 'My draft recipes',
                'bookmarks' => 'My bookmarks',
                default => 'Discover recipes',
            },
            'catalogue.index' => 'Food catalogue',
            'admin.catalogue.submission' => 'Review manual submission',
            'admin.catalogue.candidate' => 'Review duplicate candidate',
            'admin.catalogue.correction' => 'Review catalogue correction',
            'admin.catalogue.decision' => 'Moderation decision',
            'admin.catalogue.provider-refresh' => 'Review provider refresh',
            'security.second-factor.confirm' => 'Confirm your authenticator',
            default => 'Recipes, food and meal planning',
        };
@endphp
<title>{{ $topic }} — VibeDietr</title>
