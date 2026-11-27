import { router, usePage } from '@inertiajs/react';
import { UserCheck, UserX } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { ConfirmDeleteDialog } from '@/components/admin/confirm-delete-dialog';
import { Button } from '@/components/ui/button';
import { SUPER_ADMIN_ROLE } from '@/types/admin';
import type { AdminUser } from '@/types/admin';

interface AccountStatusActionProps {
    user: AdminUser;
    statusUrl: string;
}

/**
 * Switches an account off or on again. Accounts are never deleted. Only a
 * super-admin sees this, and never for a super-admin account.
 */
export function AccountStatusAction({
    user,
    statusUrl,
}: AccountStatusActionProps) {
    const { t } = useTranslation();
    const actorIsSuperAdmin =
        usePage().props.auth?.user?.is_super_admin ?? false;
    const targetIsSuperAdmin = user.roles?.some(
        (role) => role.name === SUPER_ADMIN_ROLE,
    );

    if (!actorIsSuperAdmin || targetIsSuperAdmin) {
        return null;
    }

    const isInactive = user.deactivated_at !== null;
    const label = isInactive ? t('Activate') : t('Deactivate');
    const Icon = isInactive ? UserCheck : UserX;

    return (
        <ConfirmDeleteDialog
            description={
                isInactive
                    ? t('Let {{name}} sign in again?', { name: user.name })
                    : t(
                          'Make {{name}} inactive? They are signed out and cannot sign in. Their data is kept.',
                          { name: user.name },
                      )
            }
            confirmLabel={label}
            onConfirm={() =>
                router.patch(
                    statusUrl,
                    { active: isInactive },
                    { preserveScroll: true },
                )
            }
        >
            <Button
                variant="ghost"
                size="icon"
                className="text-muted-foreground hover:text-foreground"
                title={label}
                aria-label={label}
            >
                <Icon className="h-4 w-4" />
            </Button>
        </ConfirmDeleteDialog>
    );
}
