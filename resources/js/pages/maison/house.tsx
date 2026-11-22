import { useTranslation } from 'react-i18next';
import { MaisonHouseGuide } from '@/components/maison/house/maison-house-guide';
import { MaisonSeoHead } from '@/components/maison/seo/maison-seo-head';
import { Section, Wrap } from '@/components/maison/ui/section';

/**
 * /huis — light house guide matching the intro illustration (Warm Ivory,
 * Deep Chocolate lines). No greeting, register, or entrance animation.
 */
export default function House() {
    const { t } = useTranslation();

    return (
        <>
            <MaisonSeoHead />

            <Section tone="cream" className="text-center">
                <Wrap className="max-w-200">
                    <p className="font-sans text-[9px] font-light tracking-[0.4em] text-choc/55 uppercase">
                        Maison Anversa
                    </p>
                    <h1 className="mt-3.5 font-serif text-[clamp(36px,5vw,56px)] leading-[1.1] font-normal text-choc">
                        {t('The House')}
                    </h1>
                    <p className="mx-auto mt-5 max-w-140 text-[15px] leading-[1.85] text-choc3">
                        {t(
                            'Eight rooms, eight chapters. Click a window to enter: each room takes you to a part of the house.',
                        )}
                    </p>
                </Wrap>
            </Section>

            <Section tone="cream" padded={false} className="pb-22.5">
                <Wrap className="max-w-240">
                    <MaisonHouseGuide />
                </Wrap>
            </Section>
        </>
    );
}
