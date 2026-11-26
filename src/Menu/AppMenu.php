<?php

declare(strict_types=1);

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\MenuSlot;

/**
 * The home page is the faceted search, so the navbar has no links of its own: the logo is Home, GitHub sits in the top
 * row, and the admin tools (messenger monitor, entities, workflows, ...) come from ADMIN_NAVBAR_MENU.
 * Someday: an About page in NAVBAR_PRIMARY.
 */
final class AppMenu
{
    use MenuBuilderTrait;

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function workflows(MenuEvent $event): void
    {
        $this->add($event->getMenu(), route: 'survos_workflow_extras', rp: ['flowCode' => \App\Workflow\PackageWorkflow::WORKFLOW_NAME], label: 'Workflow', icon: 'tabler:git-branch', translationDomain: false);
    }

    #[AsMenuListener(MenuSlot::NAVBAR_PRIMARY)]
    public function ask(MenuEvent $event): void
    {
        $this->add($event->getMenu(), route: 'ask', label: 'Ask', icon: 'tabler:message-chatbot', translationDomain: false);
        // Claude, ChatGPT and Claude Code setup for /mcp, from survos/ai-chat-bundle's mcp_connect.html.twig
        $this->add($event->getMenu(), route: 'ask_connect', label: 'Connect your assistant', icon: 'tabler:plug-connected', translationDomain: false);
    }

    private const GITHUB ='https://github.com/survos-sites/packages';

    #[AsMenuListener(MenuSlot::NAVBAR_END)]
    public function github(MenuEvent $event): void
    {
        $this->add($event->getMenu(), uri: self::GITHUB, label: 'GitHub', icon: 'tabler:brand-github', external: true, translationDomain: false)
            ->setExtra('tooltip', 'Source on GitHub');
    }

    #[AsMenuListener(MenuSlot::FOOTER)]
    public function footer(MenuEvent $event): void
    {
        $this->add($event->getMenu(), uri: self::GITHUB, label: 'GitHub', translationDomain: false);
    }
}
