import { Link, usePage } from '@inertiajs/react';
import { Book, LayoutGrid, Plus, Users } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import GitHubAvatar from '@/components/github-avatar';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { dashboard } from '@/routes';
import { create, edit, students, teams } from '@/routes/classrooms';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

export function AppSidebar() {
    const { teacherNavigation } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                {teacherNavigation.can_create_classroom && (
                    <SidebarGroup className="px-2 py-0">
                        <SidebarGroupLabel>Classrooms</SidebarGroupLabel>
                        <SidebarMenu>
                            {teacherNavigation.classrooms.map((classroom) => {
                                const classroomHref = classroom.organization
                                    ? students(classroom.id)
                                    : edit(classroom.id);

                                return (
                                    <SidebarMenuItem key={classroom.id}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={
                                                isCurrentUrl(
                                                    students(classroom.id),
                                                ) ||
                                                isCurrentUrl(
                                                    teams(classroom.id),
                                                )
                                            }
                                            tooltip={{
                                                children: classroom.name,
                                            }}
                                        >
                                            <Link href={classroomHref} prefetch>
                                                <GitHubAvatar
                                                    name={classroom.name}
                                                    avatarUrl={
                                                        classroom.organization
                                                            ? `https://github.com/${encodeURIComponent(classroom.organization)}.png?size=80`
                                                            : null
                                                    }
                                                    alt={
                                                        classroom.organization
                                                            ? `${classroom.organization} GitHub organization`
                                                            : undefined
                                                    }
                                                    className="size-5 rounded-sm"
                                                />
                                                <span>{classroom.name}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                        {classroom.organization && (
                                            <SidebarMenuSub>
                                                <SidebarMenuSubItem>
                                                    <SidebarMenuSubButton
                                                        asChild
                                                        isActive={isCurrentUrl(
                                                            students(
                                                                classroom.id,
                                                            ),
                                                        )}
                                                    >
                                                        <Link
                                                            href={students(
                                                                classroom.id,
                                                            )}
                                                            prefetch
                                                        >
                                                            <Users />
                                                            <span>
                                                                Student Roster
                                                            </span>
                                                        </Link>
                                                    </SidebarMenuSubButton>
                                                </SidebarMenuSubItem>
                                                <SidebarMenuSubItem>
                                                    <SidebarMenuSubButton
                                                        asChild
                                                        isActive={isCurrentUrl(
                                                            teams(classroom.id),
                                                        )}
                                                    >
                                                        <Link
                                                            href={teams(
                                                                classroom.id,
                                                            )}
                                                            prefetch
                                                        >
                                                            <LayoutGrid />
                                                            <span>Teams</span>
                                                        </Link>
                                                    </SidebarMenuSubButton>
                                                </SidebarMenuSubItem>
                                            </SidebarMenuSub>
                                        )}
                                    </SidebarMenuItem>
                                );
                            })}
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentUrl(create())}
                                    tooltip={{ children: 'Create Class' }}
                                >
                                    <Link href={create()} prefetch>
                                        <Plus />
                                        <span>Create Class</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                <SidebarMenuButton
                    asChild
                    // isActive={isCurrentUrl(item.href)}
                    tooltip={{ children: 'Documentation' }}
                >
                    <Link href={'/docusaurus'} prefetch>
                        <Book />
                        <span>Documentation</span>
                    </Link>
                </SidebarMenuButton>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
