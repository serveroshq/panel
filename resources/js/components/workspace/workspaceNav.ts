import { trans } from 'laravel-vue-i18n';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Component, ComputedRef } from 'vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { docsUrl } from '@/lib/docs';
import { extensionIcon } from '@/lib/extensionIcons';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import { help, home, integrations, setup } from '@/routes/workspace';
import activity from '@/routes/workspace/activity';
import agents from '@/routes/workspace/agents';
import backups from '@/routes/workspace/backups';
import code from '@/routes/workspace/code';
import fixes from '@/routes/workspace/fixes';
import github from '@/routes/workspace/github';
import logs from '@/routes/workspace/logs';
import machines from '@/routes/workspace/machines';
import members from '@/routes/workspace/members';
import monitors from '@/routes/workspace/monitors';
import projects from '@/routes/workspace/projects';
import services from '@/routes/workspace/services';
import settings from '@/routes/workspace/settings';
import updates from '@/routes/workspace/updates';
import workflows from '@/routes/workspace/workflows';
import type { SetupGuide, Workspace } from '@/types';

export type WorkspaceNavSection = 'menu' | 'other' | 'hidden';

export type WorkspaceNavChild = {
    key: string;
    label: string;
    href: string;
    current: boolean;
};

export type WorkspaceNavItem = {
    key: string;
    label: string;
    icon: FilledIconName | Component;
    href: string;
    external?: boolean;
    section: WorkspaceNavSection;
    current: boolean;
    bright?: boolean;
    badge?: number | null;
    children?: WorkspaceNavChild[];
};

export type WorkspaceNavGroupKey = 'machines' | 'developers';

type ExtensionNavItem = {
    key: string;
    label: string;
    icon: string;
    path: string;
    group: string;
    components: string[];
    after: string | null;
    before?: string | null;
};

const is = (component: string, ...names: string[]): boolean =>
    names.includes(component);

export function remainingSetupSteps(guide: SetupGuide | null): number | null {
    if (!guide) {
        return null;
    }

    const left = guide.groups
        .flatMap((group) => group.steps)
        .filter((step) => !step.done).length;

    return left > 0 ? left : null;
}

export function useWorkspaceNav(): ComputedRef<WorkspaceNavItem[]> {
    const page = usePage<{
        workspace?: Workspace;
        setupGuide?: SetupGuide | null;
        setupStepsLeft?: number | null;
        docsUrl: string;
        agentsPreview?: boolean;
        githubAppSetup?: boolean;
        extensions?: { nav: ExtensionNavItem[] } | null;
    }>();

    return computed(() => {
        const slug = page.props.workspace?.slug;

        if (!slug) {
            return [];
        }

        const component = page.component;
        const url = (route: { url: string }): string => route.url;
        const extensions = page.props.extensions?.nav ?? [];
        const extensionChildren = (group: string): WorkspaceNavChild[] =>
            extensions
                .filter((item) => item.group === group)
                .map((item) => ({
                    key: item.key,
                    label: item.label,
                    href: workspacePath(item.path),
                    current: item.components.includes(component),
                }));

        const machineChildren: WorkspaceNavChild[] = [
            {
                key: 'services',
                label: trans('Services'),
                href: url(onWorkspace(services.index(slug))),
                current: is(
                    component,
                    'workspace/Services',
                    'workspace/ServiceShow',
                ),
            },
            {
                key: 'updates',
                label: trans('Updates'),
                href: url(onWorkspace(updates.index(slug))),
                current: is(component, 'workspace/Updates'),
            },
            {
                key: 'backups',
                label: trans('Backups'),
                href: url(onWorkspace(backups.index(slug))),
                current: is(component, 'workspace/Backups'),
            },
            ...extensionChildren('servers'),
        ];

        const developerChildren: WorkspaceNavChild[] = [
            {
                key: 'code',
                label: trans('Code'),
                href: url(onWorkspace(code.index(slug))),
                current: component.startsWith('workspace/code/'),
            },
            {
                key: 'api',
                label: trans('API keys'),
                href: url(onWorkspace(settings.api(slug))),
                current: is(component, 'workspace/settings/Api'),
            },
            {
                key: 'mcp',
                label: trans('AI and MCP'),
                href: url(onWorkspace(settings.mcp(slug))),
                current: is(component, 'workspace/settings/Mcp'),
            },
            ...(page.props.githubAppSetup
                ? [
                      {
                          key: 'github',
                          label: trans('GitHub App'),
                          href: url(onWorkspace(github.app(slug))),
                          current: is(
                              component,
                              'workspace/settings/GitHubApp',
                          ),
                      },
                  ]
                : []),
            ...(page.props.agentsPreview
                ? [
                      {
                          key: 'agents',
                          label: trans('Agents'),
                          href: url(onWorkspace(agents.index(slug))),
                          current: component.startsWith('workspace/Agent'),
                      },
                  ]
                : []),
        ];

        const settingsOwned =
            (component.startsWith('workspace/settings/') &&
                !developerChildren.some((child) => child.current)) ||
            is(component, 'workspace/Billing');

        const items: WorkspaceNavItem[] = [
            {
                key: 'home',
                label: trans('Home'),
                icon: 'house-fill',
                href: url(onWorkspace(home(slug))),
                section: 'menu',
                current: is(component, 'workspace/Home'),
            },
            {
                key: 'projects',
                label: trans('Projects'),
                icon: 'projects-fill',
                href: url(onWorkspace(projects.index(slug))),
                section: 'menu',
                current: is(
                    component,
                    'workspace/Projects',
                    'workspace/ProjectShow',
                ),
            },
            {
                key: 'machines',
                label: trans('Machines'),
                icon: 'machines-fill',
                href: url(onWorkspace(machines.index(slug))),
                section: 'menu',
                bright: true,
                current: is(
                    component,
                    'workspace/Machines',
                    'workspace/MachineShow',
                    'workspace/MachineSettings',
                ),
                children: machineChildren,
            },
            {
                key: 'monitors',
                label: trans('Monitors'),
                icon: 'monitors-fill',
                href: url(onWorkspace(monitors.index(slug))),
                section: 'menu',
                current: is(
                    component,
                    'workspace/Monitors',
                    'workspace/MonitorShow',
                    'workspace/Logs',
                    'workspace/Workflows',
                    'workspace/WorkflowEditor',
                ),
            },
            {
                key: 'activity',
                label: trans('Activity'),
                icon: 'activity-fill',
                href: url(onWorkspace(activity.index(slug))),
                section: 'menu',
                current: is(component, 'workspace/Activity', 'workspace/Fixes'),
            },
            {
                key: 'integrations',
                label: trans('Integrations'),
                icon: 'package-fill',
                href: url(onWorkspace(integrations(slug))),
                section: 'menu',
                current: is(
                    component,
                    'workspace/Integrations',
                    'workspace/IntegrationShow',
                ),
            },
            {
                key: 'setup',
                label: trans('Setup guide'),
                icon: 'setup-fill',
                href: url(onWorkspace(setup(slug))),
                section:
                    (page.props.setupStepsLeft ?? 0) > 0 ||
                    is(component, 'workspace/SetupGuide')
                        ? 'menu'
                        : 'hidden',
                current: is(component, 'workspace/SetupGuide'),
                badge: remainingSetupSteps(page.props.setupGuide ?? null),
            },
            {
                key: 'developers',
                label: trans('Developers'),
                icon: 'code-fill',
                href: url(onWorkspace(code.index(slug))),
                section: 'menu',
                current: false,
                children: developerChildren,
            },
            {
                key: 'docs',
                label: trans('Docs'),
                icon: 'docs-fill',
                href: docsUrl(page.props.docsUrl),
                external: true,
                section: 'other',
                current: false,
            },
            {
                key: 'help',
                label: trans('Help'),
                icon: 'help-fill',
                href: url(onWorkspace(help(slug))),
                section: 'other',
                current: is(
                    component,
                    'workspace/Help',
                    'workspace/Support',
                    'workspace/SupportNew',
                    'workspace/SupportShow',
                ),
            },
            {
                key: 'settings',
                label: trans('Settings'),
                icon: 'settings-fill',
                href: url(onWorkspace(settings.general(slug))),
                section: 'other',
                current: settingsOwned,
            },
            {
                key: 'logs',
                label: trans('Logs'),
                icon: 'file-fill',
                href: url(onWorkspace(logs.index(slug))),
                section: 'hidden',
                current: false,
            },
            {
                key: 'workflows',
                label: trans('Workflows'),
                icon: 'branch-fill',
                href: url(onWorkspace(workflows.index(slug))),
                section: 'hidden',
                current: false,
            },
            {
                key: 'fixes',
                label: trans('Fixes'),
                icon: 'sparkle-fill',
                href: url(onWorkspace(fixes.index(slug))),
                section: 'hidden',
                current: false,
            },
            {
                key: 'members',
                label: trans('Members'),
                icon: 'user-fill',
                href: url(onWorkspace(members.index(slug))),
                section: 'hidden',
                current: false,
            },
        ];

        for (const extension of extensions) {
            if (extension.group === 'servers' || extension.group === 'menu') {
                continue;
            }

            const section: WorkspaceNavSection =
                extension.key === 'billing'
                    ? 'hidden'
                    : extension.group === 'workspace'
                      ? 'other'
                      : 'menu';
            const item: WorkspaceNavItem = {
                key: extension.key,
                label: extension.label,
                icon: extensionIcon(extension.icon),
                href: workspacePath(extension.path),
                section,
                current:
                    section !== 'hidden' &&
                    extension.components.includes(component),
            };
            const anchor =
                section === 'other'
                    ? items.findIndex((existing) => existing.key === 'settings')
                    : section === 'menu'
                      ? items.findIndex((existing) => existing.key === 'setup')
                      : -1;

            items.splice(anchor === -1 ? items.length : anchor, 0, item);
        }

        return items;
    });
}

export function openGroupFor(items: WorkspaceNavItem[]): WorkspaceNavGroupKey {
    const developers = items.find((item) => item.key === 'developers');

    return developers?.children?.some((child) => child.current)
        ? 'developers'
        : 'machines';
}
