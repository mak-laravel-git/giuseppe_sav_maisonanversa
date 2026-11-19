import type { LucideIcon } from 'lucide-react';
import {
    Heart,
    HelpCircle,
    Mail,
    MessageSquare,
    Package,
} from 'lucide-react';

export type SiteShared = {
    phone: string | null;
    whatsapp: string;
    emailHello: string;
    emailPress: string;
    instagramUrl: string;
    whatsappHref: string;
    phoneHref: string | null;
    emailHelloHref: string;
    emailPressHref: string;
};

export type BureauPanelId = 'bestel' | 'care' | 'faq' | 'feedback';

type BureauLinkBubble = {
    kind: 'link';
    icon: LucideIcon;
    name: string;
    sub: string;
    href: string;
};

type BureauPanelBubble = {
    kind: 'panel';
    icon: LucideIcon;
    name: string;
    sub: string;
    panel: BureauPanelId;
};

export type BureauBubble = BureauLinkBubble | BureauPanelBubble;

export function buildBureauBubbles(site: SiteShared): BureauBubble[] {
    return [
        {
            kind: 'link',
            icon: Mail,
            name: 'Email us',
            sub: site.emailHello,
            href: site.emailHelloHref,
        },
        {
            kind: 'panel',
            icon: Package,
            name: 'Order status',
            sub: 'Track your order',
            panel: 'bestel',
        },
        {
            kind: 'panel',
            icon: Heart,
            name: 'Maison Care',
            sub: 'Maintenance & warranty',
            panel: 'care',
        },
        {
            kind: 'panel',
            icon: HelpCircle,
            name: 'Frequently asked questions',
            sub: 'FAQ',
            panel: 'faq',
        },
        {
            kind: 'panel',
            icon: MessageSquare,
            name: 'Your feedback',
            sub: 'Feedback',
            panel: 'feedback',
        },
    ];
}

export function buildContactDockItems(
    site: SiteShared,
): Array<
    | { icon: LucideIcon; label: string; href: string }
    | { icon: LucideIcon; label: string; panel: BureauPanelId }
> {
    return [
        { icon: Mail, label: 'Email us', href: site.emailHelloHref },
        { icon: Package, label: 'Order status', panel: 'bestel' },
        { icon: Heart, label: 'Maison Care', panel: 'care' },
        { icon: HelpCircle, label: 'Frequently asked questions', panel: 'faq' },
        { icon: MessageSquare, label: 'Your feedback', panel: 'feedback' },
    ];
}

export const BUREAU_PANEL_IDS: readonly BureauPanelId[] = [
    'bestel',
    'care',
    'faq',
    'feedback',
] as const;
