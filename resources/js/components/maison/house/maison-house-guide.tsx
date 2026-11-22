import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MaisonLink } from '@/components/maison/maison-link';
import doorHouseArt from '@/components/maison/opening/art/door-house.html?raw';
import { OPENING_ROOMS, roomHref } from '@/components/maison/opening/opening-data';
import { RoomWindow } from '@/components/maison/opening/opening-rooms';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';

/**
 * Client list order on /huis (top → bottom of the house), remapped onto
 * OPENING_ROOMS so window hotspots stay tied to .lw0–.lw7 in the artwork.
 *
 * Display numeral → opening numeral that owns the window + destination.
 */
const HOUSE_LIST_ORDER = [
    { display: 'I', opening: 'VI' },
    { display: 'II', opening: 'VII' },
    { display: 'III', opening: 'VIII' },
    { display: 'IV', opening: 'III' },
    { display: 'V', opening: 'IV' },
    { display: 'VI', opening: 'V' },
    { display: 'VII', opening: 'I' },
    { display: 'VIII', opening: 'II' },
] as const;

/**
 * The light house from the intro: static (door open, no greeting/register),
 * eight clickable windows, and the room list in the client's Roman order.
 */
export function MaisonHouseGuide() {
    const { t } = useTranslation();
    const { locale } = useLocale();
    const [hovered, setHovered] = useState<number | null>(null);
    const art = useRef<HTMLDivElement>(null);

    useEffect(() => {
        OPENING_ROOMS.forEach((_, index) => {
            art.current
                ?.querySelector(`.lw${index}`)
                ?.classList.toggle('is-on', index === hovered);
        });
    }, [hovered]);

    const listRooms = HOUSE_LIST_ORDER.map(({ display, opening }) => {
        const index = OPENING_ROOMS.findIndex(
            (room) => room.numeral === opening,
        );
        const room = OPENING_ROOMS[index];

        return { display, index, room };
    });

    return (
        <div className="ma-house-page is-done">
            <div className="op-house mx-auto" ref={art}>
                <svg
                    className="mh-house"
                    viewBox="958 142 662 796"
                    aria-hidden="true"
                    focusable="false"
                    dangerouslySetInnerHTML={{ __html: doorHouseArt }}
                />

                {OPENING_ROOMS.map((room, index) => (
                    <RoomWindow
                        key={room.numeral}
                        room={room}
                        index={index}
                        locale={locale}
                        hovered={hovered}
                        onHover={setHovered}
                        onLeave={() => undefined}
                    />
                ))}
            </div>

            <p className="op-k mt-8">
                {t('Choose a window or a room below')}
            </p>

            <ol className="house-room-list">
                {listRooms.map(({ display, index, room }) => {
                    if (!room) {
                        return null;
                    }

                    const text = room.text[locale];

                    return (
                        <li key={display}>
                            <MaisonLink
                                href={roomHref(room, locale)}
                                className={cn(hovered === index && 'hov')}
                                onPointerEnter={() => setHovered(index)}
                                onPointerLeave={() => setHovered(null)}
                                onFocus={() => setHovered(index)}
                                onBlur={() => setHovered(null)}
                            >
                                <span className="op-rl-n">{display}</span>
                                <span className="op-rl-nm">{text.name}</span>
                                <span className="op-rl-lb">{text.subtitle}</span>
                                <span className="op-rl-ar" aria-hidden="true">
                                    →
                                </span>
                            </MaisonLink>
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}
