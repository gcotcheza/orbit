<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Alerts\DealSummary;
use App\Application\Alerts\DigestNotice;
use App\Application\Alerts\RouteDealNotice;
use App\Application\Alerts\RuleDigest;
use App\Application\Alerts\RuleMatchNotice;
use App\Notifications\RouteDealAlert;
use App\Notifications\RuleMatchAlert;
use App\Notifications\WeeklyDigest;
use Illuminate\Mail\Markdown;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

/**
 * What the three Orbit mails actually look like when they are rendered.
 *
 * ===========================================================================
 * WHY A TEST AT ALL FOR SOMETHING THAT IS JUDGED BY LOOKING AT IT
 *
 * Because almost everything that breaks an HTML mail breaks it SILENTLY. The
 * markdown mailer has three failure modes that all render perfectly valid HTML
 * containing the wrong thing, and every one of them was hit while this design
 * was being built:
 *
 *   - `mail.markdown.paths` unset, so resources/views/vendor/mail is ignored
 *     and Laravel's stock grey-box layout is sent instead. No error.
 *   - the theme file's own comments deleting the rule below them, because
 *     TijsVerkoyen's cleanup strips media queries with a regex that reaches to
 *     the first closing brace and does it before comments are removed. No error,
 *     just a mail with no font.
 *   - the banner resolved through `asset()`, which is http://localhost in a
 *     test and a staging host in staging — a permanently broken picture in a
 *     mail that has already been delivered. No error, ever.
 *
 * None of the three is visible in a screenshot of a happy path, so the checks
 * are here: the theme is reaching the markup, the media queries survived to the
 * reader, the picture is hosted where it will still be, and no mail has quietly
 * gone back to being a table.
 *
 * ===========================================================================
 * THE COPY ASSERTIONS ARE NOT DECORATION EITHER
 *
 * The subcopy quotes config('orbit.alerts.*') and the body quotes statistics
 * the reader is being asked to act on. A redesign is exactly the change most
 * likely to drop a sentence nobody notices is missing — "why am I getting
 * this" is not visible until somebody wants it — so the lines that make the
 * mail honest are asserted, in both the HTML part and the plain-text one.
 */
final class MailRenderTest extends TestCase
{
    private const BANNER = 'https://flights.ghiecode.io/mail/header.png';

    private Markdown $markdown;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markdown = $this->app->make(Markdown::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The wiring the design cannot work without
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_mailer_is_pointed_at_the_published_orbit_theme(): void
    {
        $this->assertSame('orbit', config('mail.markdown.theme'));
        $this->assertContains(resource_path('views/vendor/mail'), config('mail.markdown.paths'));
        $this->assertFileExists(resource_path('views/vendor/mail/html/themes/orbit.css'));
    }

    #[Test]
    public function the_theme_reaches_the_markup_as_inline_styles(): void
    {
        $html = $this->render('route-deal');

        /* The banner plate, straight out of themes/orbit.css. If the published
         * views were being ignored this would be Laravel's own header. */
        $this->assertStringContainsString('background-color: #0a0f1e', $html);
        $this->assertStringContainsString('style=', $html);

        /* Laravel's default theme's accent. Its presence would mean the theme
         * key is not being read. */
        $this->assertStringNotContainsString('#3869d4', $html);
    }

    #[Test]
    public function the_media_queries_survive_the_inliner(): void
    {
        $html = $this->render('weekly-digest');

        /* Inlined CSS cannot carry a media query, so these have to still be in
         * a <style> block when the mail arrives — see html/layout.blade.php. */
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('@media only screen and (max-width: 620px)', $html);
        $this->assertStringContainsString('color-scheme: light dark', $html);
    }

    #[Test]
    public function every_mail_loads_its_banner_from_the_production_host(): void
    {
        foreach (['route-deal', 'rule-match', 'weekly-digest'] as $mail) {
            $html = $this->render($mail);

            $this->assertStringContainsString(self::BANNER, $html, $mail);

            /* APP_URL is http://localhost in this suite. A banner resolved
             * through asset() would say so, and would say the staging host in
             * staging — in a document that keeps fetching it for years. */
            $this->assertStringNotContainsString('localhost/mail/header.png', $html, $mail);
        }
    }

    #[Test]
    public function the_banner_ships_with_the_repository_at_the_size_it_is_served(): void
    {
        $png = public_path('mail/header.png');

        $this->assertFileExists($png);
        $this->assertFileExists(public_path('mail/header.svg'));

        $size = getimagesize($png);

        $this->assertIsArray($size);
        /* Drawn at 2x and displayed at 600x120. */
        $this->assertSame(1200, $size[0]);
        $this->assertSame(240, $size[1]);
    }

    #[Test]
    public function no_mail_is_a_column_table_any_more(): void
    {
        foreach (['route-deal', 'rule-match', 'weekly-digest'] as $mail) {
            $html = $this->render($mail);

            /* mail::table renders a markdown table, which is what the cramped
             * four-column layout was. Cards and two-cell rows have no <th>. */
            $this->assertStringNotContainsString('<th', $html, $mail);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The subjects, which the redesign was not allowed to touch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_subjects_are_unchanged(): void
    {
        $route = new RouteDealAlert(new RouteDealNotice($this->hero()), [1]);
        $rule = new RuleMatchAlert($this->ruleNotice(), [1, 2]);
        $digest = new WeeklyDigest($this->digest(), [3]);

        $this->assertSame(
            '✈ AMS→OPO €44 — 53% below usual',
            $route->toMail(new stdClass)->subject,
        );
        $this->assertSame(
            '✈ 2 new matches from €39 — “somewhere sunny under €80”',
            $rule->toMail(new stdClass)->subject,
        );
        $this->assertSame(
            'Your week in fares — 1 deal Orbit flagged',
            $digest->toMail(new stdClass)->subject,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The copy that makes each mail honest
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_deal_alert_still_says_what_it_costs_and_what_that_means(): void
    {
        $html = $this->render('route-deal');

        $this->assertStringContainsString('Amsterdam → Porto', $html);
        $this->assertStringContainsString('AMS→OPO', $html);
        $this->assertStringContainsString('€44', $html);
        $this->assertStringContainsString('53% below usual €93', $html);
        $this->assertStringContainsString('Leaving Fri 12 Jun', $html);
        $this->assertStringContainsString('Orbit scores it 88/100 — Cheap &amp; still falling', $html);

        /* The subcopy, quoting config rather than a number typed into a
         * template — cooldown_hours and further_drop_percent. */
        $this->assertStringContainsString('is on your watchlist', $html);
        $this->assertStringContainsString(
            'once every '.config('orbit.alerts.cooldown_hours').' hours',
            $html,
        );
        $this->assertStringContainsString(
            'falls another '.config('orbit.alerts.further_drop_percent').'%',
            $html,
        );

        /* And the one link the whole mail exists for. */
        $this->assertStringContainsString('https://www.aviasales.com/search/AMSOPO', $html);
    }

    #[Test]
    public function the_rule_alert_quotes_the_rule_and_the_chips_it_was_reduced_to(): void
    {
        $html = $this->render('rule-match');

        $this->assertStringContainsString('Your rule', $html);
        $this->assertStringContainsString('somewhere sunny under €80', $html);
        $this->assertStringContainsString('Under €80 · Sunny', $html);
        $this->assertStringContainsString('2 new matches', $html);
        $this->assertStringContainsString('…and 24 more at or under your cap', $html);
        $this->assertStringContainsString('Amsterdam → Faro', $html);
        $this->assertStringContainsString('Eindhoven → Alicante', $html);
    }

    #[Test]
    public function the_digest_gives_a_route_with_no_opinion_one_quiet_line_and_no_score(): void
    {
        $html = $this->render('weekly-digest');

        $this->assertStringContainsString('Rotterdam → Tirana', $html);
        $this->assertStringContainsString('Not enough data yet', $html);

        /* App\Domain\Pricing\DealScorer's "no opinion" is a score of 0. Printed,
         * it reads as a verdict of "terrible" — which is the opposite of what it
         * means, and was one of the four em-dashes in the old table's row. */
        $this->assertStringNotContainsString('0/100', $html);

        /* The sections that do have something to say still say it. */
        $this->assertStringContainsString('Your watchlist', $html);
        $this->assertStringContainsString('Your rules', $html);
        $this->assertStringContainsString('usually €93 · 88/100', $html);
        $this->assertStringContainsString(
            'in the last '.config('orbit.alerts.digest_days').' days',
            $html,
        );
    }

    #[Test]
    public function every_mail_ends_with_the_same_way_in(): void
    {
        foreach (['route-deal', 'rule-match', 'weekly-digest'] as $mail) {
            $html = $this->render($mail);

            $this->assertStringContainsString('Open Orbit', $html, $mail);
            $this->assertStringContainsString('/alerts', $html, $mail);
            $this->assertStringContainsString('Alerts screen', $html, $mail);
        }
    }

    #[Test]
    public function every_mail_carries_a_preheader_so_the_inbox_list_does_not_read_orbit(): void
    {
        foreach (['route-deal', 'rule-match', 'weekly-digest'] as $mail) {
            $this->assertStringContainsString('class="preheader"', $this->render($mail), $mail);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The plain-text part, which is a mail and not a fallback
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_text_part_is_sentences_and_not_a_flattened_card(): void
    {
        $text = $this->renderText('route-deal');

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('Amsterdam → Porto (AMS→OPO) — €44', $text);
        $this->assertStringContainsString('That is 53% below its usual €93.', $text);
        $this->assertStringContainsString('Leaving Fri 12 Jun.', $text);
        $this->assertStringContainsString('Orbit scores it 88/100 — Cheap & still falling.', $text);
        $this->assertStringContainsString('See AMS→OPO fares: https://www.aviasales.com/search/', $text);
        $this->assertStringContainsString('is on your watchlist', $text);
        $this->assertStringContainsString('Open Orbit: ', $text);
    }

    #[Test]
    public function the_digest_text_part_lists_every_section(): void
    {
        $text = $this->renderText('weekly-digest');

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('AMS→OPO €44 — 53% below usual', $text);
        $this->assertStringContainsString('Your watchlist', $text);
        $this->assertStringContainsString('Rotterdam → Tirana (RTM→TIA) — €63', $text);
        $this->assertStringContainsString('Not enough data yet', $text);
        $this->assertStringContainsString('somewhere sunny under €80', $text);
    }

    #[Test]
    public function the_rule_text_part_keeps_the_rule_and_the_overflow_count(): void
    {
        $text = $this->renderText('rule-match');

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('Your rule: “somewhere sunny under €80”', $text);
        $this->assertStringContainsString('Amsterdam → Faro (AMS→FAO) — €39', $text);
        $this->assertStringContainsString('…and 24 more at or under your cap', $text);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @param  'route-deal'|'rule-match'|'weekly-digest'  $mail
     */
    private function render(string $mail): string
    {
        return (string) $this->markdown->render('mail.'.$mail, $this->data($mail));
    }

    /**
     * @param  'route-deal'|'rule-match'|'weekly-digest'  $mail
     */
    private function renderText(string $mail): string
    {
        return (string) $this->markdown->renderText('mail.'.$mail, $this->data($mail));
    }

    /**
     * @param  'route-deal'|'rule-match'|'weekly-digest'  $mail
     * @return array<string, mixed>
     */
    private function data(string $mail): array
    {
        return match ($mail) {
            'route-deal' => ['deal' => $this->hero()],
            'rule-match' => [
                'notice' => $this->ruleNotice(),
                'deals' => $this->ruleNotice()->deals,
                'more' => 24,
            ],
            'weekly-digest' => ['digest' => $this->digest()],
        };
    }

    /** A watched route: statistics, a score and a verdict. */
    private function hero(): DealSummary
    {
        return $this->deal('AMS-OPO', 'Amsterdam', 'Porto', 4400, 9300, 53, 88, 'Cheap & still falling', '2026-06-12');
    }

    /** A rule match: a date, and neither statistics nor an opinion. */
    private function ruleNotice(): RuleMatchNotice
    {
        return new RuleMatchNotice(
            ruleId: 3,
            ruleText: 'somewhere sunny under €80',
            chips: ['Under €80', 'Sunny', 'Sep – Nov'],
            deals: [
                $this->deal('AMS-FAO', 'Amsterdam', 'Faro', 3900, date: '2026-09-18'),
                $this->deal('EIN-ALC', 'Eindhoven', 'Alicante', 4200, date: '2026-10-02'),
            ],
        );
    }

    private function digest(): DigestNotice
    {
        return new DigestNotice(
            routes: [
                $this->hero(),
                /* Inside its first min_tracking_days: no usual price, a score
                 * of 0 that means "no opinion", and the verdict that says so. */
                $this->deal('RTM-TIA', 'Rotterdam', 'Tirana', 6300, score: 0, verdict: 'Not enough data yet', date: '2026-09-05'),
            ],
            rules: [
                new RuleDigest('somewhere sunny under €80', 2, $this->ruleNotice()->deals),
            ],
            week: [$this->hero()],
        );
    }

    private function deal(
        string $code,
        string $origin,
        string $destination,
        int $cents,
        ?int $usual = null,
        ?int $percent = null,
        ?int $score = null,
        ?string $verdict = null,
        ?string $date = null,
    ): DealSummary {
        return new DealSummary(
            routeCode: $code,
            origin: $origin,
            destination: $destination,
            priceCents: $cents,
            usualCents: $usual,
            percentUnderUsual: $percent,
            score: $score,
            verdict: $verdict,
            departureDate: $date,
            bookingUrl: 'https://www.aviasales.com/search/'.str_replace('-', '', $code).'1206',
        );
    }
}
