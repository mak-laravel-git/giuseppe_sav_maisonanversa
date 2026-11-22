import { useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MaisonButton } from '@/components/maison/ui/maison-button';
import { store as cookieConsentStore } from '@/routes/cookie-consent';

export const COOKIE_SETTINGS_EVENT = 'maison:open-cookie-settings';

function parseConsent(raw: string | null | undefined): {
    necessary: boolean;
    analytics: boolean;
} | null {
    if (!raw) {
        return null;
    }

    try {
        const parsed = JSON.parse(raw) as {
            necessary?: boolean;
            analytics?: boolean;
        };

        return {
            necessary: true,
            analytics: Boolean(parsed.analytics),
        };
    } catch {
        return null;
    }
}

export function CookieConsentBanner() {
    const { t } = useTranslation();
    const { cookieConsent } = usePage<{ cookieConsent?: string | null }>()
        .props;
    const saved = parseConsent(cookieConsent);
    const [forceOpen, setForceOpen] = useState(false);
    const form = useForm({
        necessary: true,
        analytics: saved?.analytics ?? false,
    });

    useEffect(() => {
        const open = () => {
            const current = parseConsent(cookieConsent);
            form.setData('analytics', current?.analytics ?? false);
            setForceOpen(true);
        };

        window.addEventListener(COOKIE_SETTINGS_EVENT, open);

        return () => window.removeEventListener(COOKIE_SETTINGS_EVENT, open);
        // form.setData is stable enough for this reopen handler; re-bind when consent cookie changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- avoid re-subscribing on every form render
    }, [cookieConsent]);

    const visible = !cookieConsent || forceOpen;

    useEffect(() => {
        if (!visible) {
            return;
        }

        // The floating help dock hides itself while the banner is open (see ContactDock).
        document.documentElement.dataset.cookieBanner = 'open';

        return () => {
            delete document.documentElement.dataset.cookieBanner;
        };
    }, [visible]);

    if (!visible) {
        return null;
    }

    const submit = (analytics: boolean) => {
        form.transform((data) => ({ ...data, analytics }));
        form.post(cookieConsentStore.url(), {
            onSuccess: () => setForceOpen(false),
        });
    };

    return (
        <div className="fixed inset-x-0 bottom-0 z-[95] border-t border-gold/30 bg-choc p-4 text-cream shadow-lg md:p-6">
            <div className="mx-auto flex max-w-5xl flex-col gap-4 md:flex-row md:items-end">
                <div className="flex-1">
                    <p className="font-serif text-lg">{t('Cookies')}</p>
                    <p className="mt-1 text-sm text-sand">
                        {t(
                            'We use necessary cookies for the site. Analytics load only after your consent.',
                        )}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-4 text-sm">
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked disabled />
                            {t('Necessary')}
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.analytics}
                                onChange={(event) =>
                                    form.setData(
                                        'analytics',
                                        event.target.checked,
                                    )
                                }
                            />
                            {t('Analytics')}
                        </label>
                    </div>
                </div>
                <div className="flex flex-wrap gap-3">
                    <MaisonButton
                        type="button"
                        variant="gold"
                        disabled={form.processing}
                        onClick={() => submit(form.data.analytics)}
                    >
                        {t('Save preferences')}
                    </MaisonButton>
                    <MaisonButton
                        type="button"
                        variant="gold"
                        disabled={form.processing}
                        onClick={() => submit(true)}
                    >
                        {t('Accept all')}
                    </MaisonButton>
                    <MaisonButton
                        type="button"
                        variant="gold"
                        disabled={form.processing}
                        onClick={() => submit(false)}
                    >
                        {t('Refuse')}
                    </MaisonButton>
                </div>
            </div>
        </div>
    );
}

export function openCookieSettings(): void {
    window.dispatchEvent(new Event(COOKIE_SETTINGS_EVENT));
}
