<?php

namespace Database\Seeders;

use App\Enums\FaqContext;
use App\Models\Faq;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FaqSeeder extends Seeder
{
    /**
     * Contact FAQs only. Product FAQs are owned by each product and seeded
     * through ProductSeeder into `product_faqs`.
     *
     * The existing contact rows are overwritten in page order and no row is
     * deleted. Rows are saved without model events, so DeepL never translates
     * or rewords the approved texts.
     */
    public function run(): void
    {
        $existing = self::existingContactFaqs();
        $entries = self::contactFaqs();

        if ($existing->count() > count($entries)) {
            $this->command?->warn("Contact FAQs left untouched: found {$existing->count()}, expected at most ".count($entries).'.');

            return;
        }

        DB::transaction(fn () => Faq::withoutEvents(function () use ($existing, $entries): void {
            foreach ($entries as $position => $entry) {
                $faq = $existing->get($position) ?? new Faq(['context' => FaqContext::Contact]);

                $faq->fill([
                    ...$entry['nl'],
                    'sort_order' => $position,
                    'is_published' => true,
                ])->save();

                foreach ($entry as $locale => $texts) {
                    foreach ($texts as $column => $value) {
                        $faq->translations()->updateOrCreate(
                            ['locale' => $locale, 'column' => $column],
                            ['value' => $value, 'source_hash' => $faq->translationSourceHash($column)],
                        );
                    }
                }
            }
        }));
    }

    /**
     * The contact FAQ rows in the order the page lists them.
     *
     * @return Collection<int, Faq>
     */
    public static function existingContactFaqs(): Collection
    {
        return Faq::query()
            ->where('context', FaqContext::Contact->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Client-approved wording (26 Nov 2026), in page order. Dutch is the source
     * text; every language is copied exactly as supplied.
     *
     * @return list<array{
     *     nl: array{question: string, answer: string},
     *     en: array{question: string, answer: string},
     *     fr: array{question: string, answer: string}
     * }>
     */
    public static function contactFaqs(): array
    {
        return [
            [
                'nl' => [
                    'question' => 'Wanneer wordt Heritage Nº 001 geleverd?',
                    'answer' => 'De levering is gepland in het eerste kwartaal van 2027. Je kiest je nummer zelf bij het afrekenen. Zodra je racket vertrekt, ontvang je een e-mail met je trackinglink.',
                ],
                'en' => [
                    'question' => 'When will Heritage Nº 001 be delivered?',
                    'answer' => 'Delivery is planned for the first quarter of 2027. You choose your own number at checkout. As soon as your racket ships, you will receive an email with your tracking link.',
                ],
                'fr' => [
                    'question' => 'Quand Heritage Nº 001 sera-t-il livré ?',
                    'answer' => 'La livraison est prévue au premier trimestre 2027. Vous choisissez vous-même votre numéro lors du paiement. Dès l’expédition de votre raquette, vous recevez un e-mail avec votre lien de suivi.',
                ],
            ],
            [
                'nl' => [
                    'question' => 'Hoe wordt mijn racket geleverd?',
                    'answer' => 'In de Founding Edition Box, samen met je Heritage Passport, een certificaat van echtheid, een persoonlijke brief en een dust bag. Elke zending is verzekerd en traceerbaar.',
                ],
                'en' => [
                    'question' => 'How will my racket be delivered?',
                    'answer' => 'In the Founding Edition Box, together with your Heritage Passport, a certificate of authenticity, a personal letter and a dust bag. Every shipment is insured and tracked.',
                ],
                'fr' => [
                    'question' => 'Comment ma raquette sera-t-elle livrée ?',
                    'answer' => 'Dans la Founding Edition Box, accompagnée de votre Heritage Passport, d’un certificat d’authenticité, d’une lettre personnelle et d’une housse. Chaque envoi est assuré et suivi.',
                ],
            ],
            [
                'nl' => [
                    'question' => 'Kan ik mijn racket retourneren?',
                    'answer' => 'Ja. Je kunt je aankoop binnen 14 dagen na ontvangst zonder opgave van reden herroepen, via je account (Bestellingen → Retourneren) of per e-mail. Stuur het racket compleet terug, met het Heritage Passport, het certificaat, de dust bag en de originele verpakking. De kosten van de retourzending zijn voor jou. Alle details vind je in onze algemene voorwaarden.',
                ],
                'en' => [
                    'question' => 'Can I return my racket?',
                    'answer' => 'Yes. You can withdraw from your purchase within 14 days of receiving it, without giving a reason, through your account (Orders → Return) or by email. Please return the racket complete, with the Heritage Passport, the certificate, the dust bag and the original packaging. Return shipping costs are yours. You will find all details in our terms and conditions.',
                ],
                'fr' => [
                    'question' => 'Puis-je retourner ma raquette ?',
                    'answer' => 'Oui. Vous pouvez vous rétracter dans les 14 jours suivant la réception, sans donner de motif, via votre compte (Commandes → Retour) ou par e-mail. Renvoyez la raquette complète, avec le Heritage Passport, le certificat, la housse et l’emballage d’origine. Les frais de retour sont à votre charge. Tous les détails figurent dans nos conditions générales.',
                ],
            ],
            [
                'nl' => [
                    'question' => 'Is de prijs inclusief verzending?',
                    'answer' => 'Ja. Levering binnen de Europese Unie is inbegrepen in de prijs. Woon je buiten de Europese Unie, neem dan contact met ons op.',
                ],
                'en' => [
                    'question' => 'Is shipping included in the price?',
                    'answer' => 'Yes. Delivery within the European Union is included in the price. If you live outside the European Union, please contact us.',
                ],
                'fr' => [
                    'question' => 'La livraison est-elle comprise dans le prix ?',
                    'answer' => 'Oui. La livraison au sein de l’Union européenne est comprise dans le prix. Si vous habitez en dehors de l’Union européenne, contactez-nous.',
                ],
            ],
            [
                'nl' => [
                    'question' => 'Hoe onderhoud ik het leder?',
                    'answer' => 'De greep is van rundleder. Bewaar je racket droog, weg van hitte en direct zonlicht, bij voorkeur in zijn dust bag. Veeg het leder af met een zachte, droge doek.',
                ],
                'en' => [
                    'question' => 'How do I care for the leather?',
                    'answer' => 'The grip is made of cowhide leather. Keep your racket dry, away from heat and direct sunlight, preferably in its dust bag. Wipe the leather with a soft, dry cloth.',
                ],
                'fr' => [
                    'question' => 'Comment entretenir le cuir ?',
                    'answer' => 'Le grip est en cuir de vachette. Conservez votre raquette au sec, à l’abri de la chaleur et du soleil direct, de préférence dans sa housse. Essuyez le cuir avec un chiffon doux et sec.',
                ],
            ],
            [
                'nl' => [
                    'question' => 'Kan ik het racket personaliseren?',
                    'answer' => 'Elke Founding Edition draagt een eigen nummer, dat je zelf kiest bij het afrekenen. Na de retourtermijn wordt je nummer opgenomen in het Founding Circle Register, met of zonder je naam, zoals jij kiest. Andere personalisatie is er voor de Founding Edition niet.',
                ],
                'en' => [
                    'question' => 'Can I personalise the racket?',
                    'answer' => 'Every Founding Edition carries its own number, which you choose at checkout. After the return period, your number is entered in the Founding Circle Register, with or without your name, as you choose. No other personalisation is offered for the Founding Edition.',
                ],
                'fr' => [
                    'question' => 'Puis-je personnaliser la raquette ?',
                    'answer' => 'Chaque Founding Edition porte son propre numéro, que vous choisissez lors du paiement. Après le délai de retour, votre numéro est inscrit au Founding Circle Register, avec ou sans votre nom, selon votre choix. Aucune autre personnalisation n’est proposée pour la Founding Edition.',
                ],
            ],
        ];
    }
}
