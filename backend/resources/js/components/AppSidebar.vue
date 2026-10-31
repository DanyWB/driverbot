<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bike,
    CalendarRange,
    ClipboardList,
    Coins,
    LayoutDashboard,
    Users,
    Globe,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';
import { useWebsiteCms } from '@/composables/useWebsiteCms';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutDashboard,
    },
    {
        title: 'Timeline',
        href: '/timeline',
        icon: CalendarRange,
    },
    {
        title: 'Bookings',
        href: '/bookings',
        icon: ClipboardList,
    },
    {
        title: 'Fleet',
        href: '/vehicles',
        icon: Bike,
    },
    {
        title: 'Pricing presets',
        href: '/pricing-presets',
        icon: Coins,
    },
    {
        title: 'Customers',
        href: '/customers',
        icon: Users,
    },
];
const page = usePage();
const { ct } = useWebsiteCms();
const websiteCms = computed(
    () =>
        (page.props.websiteCms as { can_manage?: boolean } | undefined)
            ?.can_manage === true,
);
const websiteNav = computed<NavItem[]>(() => [
    { title: ct('Website'), href: '/website/cards', icon: Globe },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="websiteCms"
                :items="websiteNav"
                :label="ct('Website')"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
