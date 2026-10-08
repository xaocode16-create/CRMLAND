<?php

namespace App\View\Composers;

use App\Support\Navigation\CrmNavigation;
use Illuminate\View\View;

final class AppSidebarComposer
{
    public function __construct(private CrmNavigation $navigation) {}

    public function compose(View $view): void
    {
        if (! str_ends_with($view->name(), 'app.sidebar') || ! auth()->check()) {
            return;
        }

        $user = auth()->user();

        $view->with([
            'navGroups' => $this->navigation->groupsFor($user),
            'accountItems' => $this->navigation->accountItemsFor($user),
        ]);
    }
}
