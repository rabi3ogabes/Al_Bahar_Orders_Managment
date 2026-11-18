<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Support\IntroSlides;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

// The words of the customer app's three intro slides: edited in Settings → General & brand → "Intro screens" (12 plain-text settings, an empty one meaning
// "use the app's own words") and served by GET /api/v1/app-config as intro.slides[].{title,body}.{en,ar} (a text or null).

/** The page as a super admin sees it, on the General & brand tab. */
function introPage()
{
    test()->actingAs(staffWithRole('super_admin'));

    return Livewire::test(SettingsIndex::class)->set('tab', 'general');
}

function introField(int $slide, string $part, string $lang): string
{
    return 'values.general.'.IntroSlides::field($slide, $part, $lang);
}

function introServed(): array
{
    return test()->getJson('/api/v1/app-config')->assertOk()->json('data.intro.slides');
}

function introNone(): array
{
    return ['title' => ['en' => null, 'ar' => null], 'body' => ['en' => null, 'ar' => null]];
}

/** @return array<string, array<string, string>> a slide's four texts as the dashboard holds them */
function introTexts(string $text = ''): array
{
    return ['title' => ['en' => $text, 'ar' => $text], 'body' => ['en' => $text, 'ar' => $text]];
}

it('serves three slides of null texts until the admin writes something, next to every key the apps already read', function () {
    $r = $this->getJson('/api/v1/app-config')->assertOk()
        ->assertJsonPath('data.designed_by.en', 'Designed by Athar Qatar')->assertJsonPath('data.returns_enabled', true)
        ->assertJsonPath('data.alternate_phone_enabled', true)->assertJsonPath('data.ops.status_style', 'buttons')
        ->assertJsonCount(3, 'data.intro.slides');

    foreach ($r->json('data.intro.slides') as $slide) {
        expect($slide)->toBe(['title' => ['en' => null, 'ar' => null], 'body' => ['en' => null, 'ar' => null]]);
    }
    // The key order of the answer: the old keys keep their place, the new one is added.
    expect(array_keys($r->json('data')))->toBe(['designed_by', 'intro', 'returns_enabled', 'alternate_phone_enabled', 'ops']);
});

it('serves the admin text of one language and leaves the rest null, trimmed and as plain text', function () {
    $s = app(Settings::class);
    $s->set(IntroSlides::key(2, 'title', 'ar'), '  عنوان مخصص  ');
    $s->set(IntroSlides::key(3, 'body', 'en'), "Bid live,\n  and browse\tthe <b>collections</b>.");

    $slides = introServed();
    expect($slides[1]['title'])->toBe(['en' => null, 'ar' => 'عنوان مخصص'])->and($slides[1]['body'])->toBe(['en' => null, 'ar' => null])
        ->and($slides[2]['body']['en'])->toBe('Bid live, and browse the collections.')
        ->and($slides[0])->toBe(introNone());
});

it('never serves a stored value that is unsafe or out of shape: markup, control characters, wrong types, too much text', function () {
    $s = app(Settings::class);
    $s->set(IntroSlides::key(1, 'title', 'en'), '<script>alert(1)</script><b></b>');
    $s->set(IntroSlides::key(1, 'title', 'ar'), "نص\x07بجرس");
    $s->set(IntroSlides::key(1, 'body', 'en'), ['not', 'text']);
    $s->set(IntroSlides::key(1, 'body', 'ar'), 12345);
    $s->set(IntroSlides::key(2, 'title', 'en'), str_repeat('x', 300));
    $s->set(IntroSlides::key(2, 'body', 'en'), str_repeat('ب', 500));
    $s->set(IntroSlides::key(3, 'title', 'en'), "\u{202E}reversed");
    $s->set(IntroSlides::key(3, 'title', 'ar'), '   ');

    $slides = introServed();
    expect($slides[0]['title']['en'])->toBe('alert(1)')
        ->and($slides[0]['title']['ar'])->toBeNull()->and($slides[0]['body']['en'])->toBeNull()->and($slides[0]['body']['ar'])->toBeNull()
        ->and($slides[1]['title']['en'])->toBe(str_repeat('x', 40))->and(mb_strlen($slides[1]['body']['en']))->toBe(120)
        ->and($slides[1]['body']['ar'])->toBeNull()->and($slides[2]['title']['en'])->toBeNull()->and($slides[2]['title']['ar'])->toBeNull();
});

it('keeps the one-minute public cache and shows an admin change at once on the server side', function () {
    $r = $this->getJson('/api/v1/app-config')->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');

    // Warm the settings cache (a first answer), change a text on the dashboard: the very next answer carries it, so the only delay a phone sees is the
    // 60 seconds of `max-age`, never a stale server cache.
    expect(introServed()[0]['title']['en'])->toBeNull();
    introPage()->set(introField(1, 'title', 'en'), 'Hala, homes')->call('save')->assertHasNoErrors();
    expect(introServed()[0]['title']['en'])->toBe('Hala, homes');
    introPage()->set(introField(1, 'title', 'en'), '')->call('save')->assertHasNoErrors();
    expect(introServed()[0]['title']['en'])->toBeNull();
});

it('puts the twelve fields on General & brand with the shipped texts as placeholders, empty until the admin writes', function () {
    $page = introPage()->assertSee(__('intro_admin.section_title'));
    $schema = SettingsIndex::schema()['general'];

    foreach (IntroSlides::slideNumbers() as $n) {
        foreach (IntroSlides::PARTS as $part) {
            foreach (IntroSlides::LANGS as $lang) {
                $key = IntroSlides::key($n, $part, $lang);
                expect($schema)->toHaveKey($key)->and($schema[$key]['intro'])->toBeTrue()->and($schema[$key]['dir'])->toBe($lang === 'ar' ? 'rtl' : 'ltr');
                $default = config("platform.customer_app.intro_defaults.{$n}.{$part}.{$lang}");
                expect($default)->toBeString()->not->toBe('');
                $page->assertSet(introField($n, $part, $lang), '')->assertSeeHtml('placeholder="'.e($default).'"');
            }
        }
    }
    // Nothing is stored by merely opening the page, and the generic field list does not draw them a second time.
    expect(app(Settings::class)->get(IntroSlides::key(1, 'title', 'en')))->toBeNull();
    $page->assertSeeHtml('data-intro-slide="1"')->assertSeeHtml('data-intro-slide="3"');
    expect(substr_count($page->html(), 'id="'.IntroSlides::field(1, 'title', 'en').'"'))->toBe(1);
});

it('has Arabic inputs right to left and English inputs left to right, and a preview of every slide in both languages', function () {
    $html = introPage()->html();
    foreach (IntroSlides::slideNumbers() as $n) {
        foreach (IntroSlides::LANGS as $lang) {
            expect($html)->toContain('data-intro-preview="'.$n.'-'.$lang.'"');
            foreach (IntroSlides::PARTS as $part) {
                $id = IntroSlides::field($n, $part, $lang);
                expect($html)->toMatch('/id="'.preg_quote($id, '/').'"[^>]*dir="'.($lang === 'ar' ? 'rtl' : 'ltr').'"/');
            }
        }
    }
    expect($html)->toContain('maxlength="'.IntroSlides::TITLE_MAX.'"')->toContain('maxlength="'.IntroSlides::BODY_MAX.'"');
});

it('saves the twelve texts with the page, empty meaning default, and audits each changed text once', function () {
    $page = introPage()
        ->set(introField(1, 'title', 'en'), 'Hala! Homes')->set(introField(1, 'title', 'ar'), 'حيّاكم الله')
        ->set(introField(1, 'body', 'en'), 'Everything for your home.')->set(introField(1, 'body', 'ar'), '')
        ->call('save')->assertHasNoErrors();

    $slides = introServed();
    expect($slides[0]['title'])->toBe(['en' => 'Hala! Homes', 'ar' => 'حيّاكم الله'])->and($slides[0]['body'])->toBe(['en' => 'Everything for your home.', 'ar' => null]);

    $logs = AuditLog::where('action', 'settings.updated')->get()->filter(fn ($l) => str_starts_with($l->metadata['key'] ?? '', 'customer_app.intro_'));
    // body_ar was empty and stays empty: not a change; saving the same page again changes nothing and writes nothing.
    expect($logs->pluck('metadata.key')->sort()->values()->all())->toBe(['customer_app.intro_1_body_en', 'customer_app.intro_1_title_ar', 'customer_app.intro_1_title_en']);
    $before = AuditLog::count();
    $page->call('save')->assertHasNoErrors();
    expect(AuditLog::count())->toBe($before);

    $page->set(introField(1, 'title', 'en'), '')->call('save');
    expect(introServed()[0]['title']['en'])->toBeNull()
        ->and(AuditLog::where('action', 'settings.updated')->latest('created_at')->first()->new_values['value'])->toBeNull();
});

it('strips tags, folds white space into single spaces and trims before it counts the length', function () {
    $markup = '<b>'.str_repeat('x', 40).'</b>';   // 47 characters typed, 40 of text
    introPage()
        ->set(introField(1, 'title', 'en'), $markup)
        ->set(introField(1, 'body', 'en'), "  Pick   a time,\n\n or\tcollect\u{00A0}\u{0085} <i>from</i> a <a href=\"https://x.test\">branch</a> ")
        ->set(introField(1, 'title', 'ar'), '<p></p><br>')
        ->call('save')->assertHasNoErrors()
        ->assertSet(introField(1, 'title', 'en'), str_repeat('x', 40))
        ->assertSet(introField(1, 'body', 'en'), 'Pick a time, or collect from a branch')
        ->assertSet(introField(1, 'title', 'ar'), '');

    $s = app(Settings::class);
    expect($s->get(IntroSlides::key(1, 'title', 'en')))->toBe(str_repeat('x', 40))->and($s->get(IntroSlides::key(1, 'body', 'en')))->toBe('Pick a time, or collect from a branch')
        ->and($s->get(IntroSlides::key(1, 'title', 'ar')))->toBeNull();
});

it('cleans text for any writer: bytes that are not UTF-8 are dropped as a whole, tags and white space are folded, control characters are kept so they can be refused', function () {
    expect(IntroSlides::clean("bad \xC3\x28 utf8"))->toBe('')->and(IntroSlides::clean("  a\r\n b  "))->toBe('a b')->and(IntroSlides::clean('<p>x</p><script>y</script>'))->toBe('xy')->and(IntroSlides::clean('a <br> <b> </b> b'))->toBe('a b')
        ->and(IntroSlides::clean("a\x00b"))->toBe("a\x00b")->and(IntroSlides::served("a\x00b", 'title'))->toBeNull()->and(IntroSlides::served(null, 'body'))->toBeNull()
        ->and(IntroSlides::served(str_repeat('ب ', 100), 'body'))->toBe(rtrim(mb_substr(str_repeat('ب ', 100), 0, 120)));
});

it('keeps a lone less-than sign as text, like any other character', function () {
    introPage()->set(introField(2, 'body', 'en'), '5 < 6 and 7 > 3')->call('save')->assertHasNoErrors();
    expect(introServed()[1]['body']['en'])->toBe('5 < 6 and 7 > 3');
});

it('allows exactly 40 characters in a title and 120 in a body in each language and refuses one more, counting characters and not bytes', function () {
    $page = introPage();
    foreach (['en' => 'x', 'ar' => 'ب', 'emoji' => '🏠'] as $lang => $ch) {
        $l = $lang === 'ar' ? 'ar' : 'en';
        $page->set(introField(2, 'title', $l), str_repeat($ch, 40))->set(introField(2, 'body', $l), str_repeat($ch, 120))->call('save')->assertHasNoErrors();
        $page->set(introField(2, 'title', $l), str_repeat($ch, 41))->call('save')->assertHasErrors([introField(2, 'title', $l)]);
        $page->set(introField(2, 'title', $l), str_repeat($ch, 40))->set(introField(2, 'body', $l), str_repeat($ch, 121))->call('save')->assertHasErrors([introField(2, 'body', $l)]);
        $page->set(introField(2, 'body', $l), str_repeat($ch, 120));
    }
    $page->call('save')->assertHasNoErrors();
    expect(mb_strlen(introServed()[1]['title']['ar']))->toBe(40);
});

it('refuses control characters and direction overrides but accepts Arabic letters, marks and emoji', function (string $bad) {
    $page = introPage()->set(introField(3, 'title', 'en'), 'Before '.$bad.' after')->call('save')
        ->assertHasErrors([introField(3, 'title', 'en')]);
    expect(app(Settings::class)->get(IntroSlides::key(3, 'title', 'en')))->toBeNull();
    // The refusal is explained in words, in the admin's language.
    expect($page->errors()->first(introField(3, 'title', 'en')))->toBe(__('intro_admin.control_characters'));
})->with(["\x00", "\x07", "\x1B", "\x7F", "\u{009F}", "\u{202E}", "\u{2066}"]);

it('accepts Arabic with marks, a right-to-left mark and emoji', function () {
    introPage()->set(introField(1, 'title', 'ar'), "حيّاكم الله \u{200F}🏠")->call('save')->assertHasNoErrors();
    expect(introServed()[0]['title']['ar'])->toBe("حيّاكم الله \u{200F}🏠");
});

it('refuses a value that is not text', function () {
    introPage()->set(introField(1, 'title', 'en'), ['a'])->call('save')->assertHasErrors([introField(1, 'title', 'en')]);
});

it('names the field in the error, in the admin language', function () {
    $page = introPage()->set(introField(1, 'title', 'en'), str_repeat('x', 41));
    app()->setLocale('ar');
    $page->call('save');
    expect($page->errors()->first(introField(1, 'title', 'en')))->toContain('40');
});

it('resets one slide at once: its four texts are emptied and saved, the other slides and unsaved fields stay, one audit entry per cleared text', function () {
    $s = app(Settings::class);
    foreach ([1, 2] as $n) {
        foreach (IntroSlides::PARTS as $part) {
            foreach (IntroSlides::LANGS as $lang) {
                $s->set(IntroSlides::key($n, $part, $lang), "{$n} {$part} {$lang}");
            }
        }
    }
    $s->set('customer_app.designed_by_en', 'Designed by Old');
    $audits = AuditLog::count();

    $page = introPage()->set('values.general.customer_app__designed_by_en', 'Designed by Typed But Not Saved');
    $page->call('resetIntroSlide', 1)->assertHasNoErrors()
        ->assertSet(introField(1, 'title', 'en'), '')->assertSet(introField(1, 'body', 'ar'), '')
        ->assertSet(introField(2, 'title', 'en'), '2 title en')
        ->assertSet('values.general.customer_app__designed_by_en', 'Designed by Typed But Not Saved');

    $slides = introServed();
    expect($slides[0])->toBe(introNone())->and($slides[1]['title']['en'])->toBe('2 title en')->and($s->get('customer_app.designed_by_en'))->toBe('Designed by Old')
        ->and(AuditLog::count())->toBe($audits + 4);
    $page->assertDispatched('toast', fn (string $event, array $p) => $p['type'] === 'success' && $p['undoEvent'] === 'intro-slide-undo' && $p['undoLabel'] === __('admin.undo')
        && $p['undoParams']['slide'] === 1 && $p['undoParams']['texts']['title']['en'] === '1 title en' && $p['undoParams']['texts']['body']['ar'] === '1 body ar');
});

it('undoes a reset with exactly what the fields held, saved again and audited', function () {
    $s = app(Settings::class);
    $texts = ['title' => ['en' => 'Hala', 'ar' => 'حيّاكم'], 'body' => ['en' => 'Body en', 'ar' => '']];
    $page = introPage();
    foreach ($texts as $part => $row) {
        foreach ($row as $lang => $text) {
            $page->set(introField(3, $part, $lang), $text);
        }
    }
    $page->call('save')->call('resetIntroSlide', 3);
    expect(introServed()[2])->toBe(introNone());

    $page->call('undoIntroSlide', 3, $texts)->assertSet(introField(3, 'title', 'ar'), 'حيّاكم')->assertSet(introField(3, 'body', 'ar'), '');
    expect(introServed()[2])->toBe(['title' => ['en' => 'Hala', 'ar' => 'حيّاكم'], 'body' => ['en' => 'Body en', 'ar' => null]])
        ->and($s->get(IntroSlides::key(3, 'body', 'ar')))->toBeNull();
    $page->assertDispatched('toast', fn (string $event, array $p) => ($p['message'] ?? null) === __('admin.saved'));
});

it('treats the texts of an undo as untrusted: cleaned, limited and ignored when they are not text', function () {
    $page = introPage();
    $page->call('undoIntroSlide', 1, [
        'title' => ['en' => '<b>Hala</b>', 'ar' => str_repeat('ب', 90)],
        'body' => ['en' => "bell\x07", 'ar' => ['x']],
    ]);
    $slides = introServed();
    expect($slides[0]['title'])->toBe(['en' => 'Hala', 'ar' => str_repeat('ب', 40)])->and($slides[0]['body'])->toBe(['en' => null, 'ar' => null]);
    // A missing part is just empty.
    $page->call('undoIntroSlide', 2, []);
    expect(introServed()[1])->toBe(introNone());
});

it('ignores a reset or undo of a slide that does not exist', function (int $slide) {
    app(Settings::class)->set(IntroSlides::key(1, 'title', 'en'), 'Keep me');
    $audits = AuditLog::count();
    $page = introPage();
    $page->call('resetIntroSlide', $slide)->call('undoIntroSlide', $slide, introTexts('x'));
    expect(introServed()[0]['title']['en'])->toBe('Keep me')->and(AuditLog::count())->toBe($audits);
})->with([0, 4, -1, 99]);

it('says so, without an Undo and without writing anything, when a slide already uses the default text', function () {
    $audits = AuditLog::count();
    introPage()->call('resetIntroSlide', 2)->assertDispatched('toast', fn (string $event, array $p) => $p['message'] === __('intro_admin.already_default', ['slide' => 2]) && ! isset($p['undoEvent']));
    expect(AuditLog::count())->toBe($audits);
});

it('lets only staff who may edit settings change, reset or undo a text; a viewer sees the fields but no Reset button', function () {
    app(Settings::class)->set(IntroSlides::key(1, 'title', 'en'), 'Stored');

    $this->actingAs(staffWithRole('auditor'));
    $viewer = Livewire::test(SettingsIndex::class)->set('tab', 'general')->assertSee(__('intro_admin.section_title'))->assertSet(introField(1, 'title', 'en'), 'Stored')
        ->assertDontSee(__('intro_admin.reset'));
    $viewer->set(introField(1, 'title', 'en'), 'Hacked')->call('save')->assertForbidden();
    Livewire::test(SettingsIndex::class)->set('tab', 'general')->call('resetIntroSlide', 1)->assertForbidden();
    Livewire::test(SettingsIndex::class)->set('tab', 'general')->call('undoIntroSlide', 1, introTexts('Hacked'))->assertForbidden();
    expect(introServed()[0]['title']['en'])->toBe('Stored');

    // Someone with no access to settings at all cannot even open the page.
    $this->actingAs(staffWithRole('inventory_manager'));
    Livewire::test(SettingsIndex::class)->assertForbidden();

    $this->actingAs(staffWithRole('super_admin'));
    Livewire::test(SettingsIndex::class)->set('tab', 'general')->assertSee(__('intro_admin.reset'))->call('resetIntroSlide', 1)->assertHasNoErrors();
    expect(introServed()[0]['title']['en'])->toBeNull();
});

it('is public like the rest of app-config: no sign-in, and a signed-in customer gets the same answer', function () {
    app(Settings::class)->set(IntroSlides::key(1, 'title', 'en'), 'For everyone');
    $guest = $this->getJson('/api/v1/app-config')->assertOk()->json('data.intro');
    actingAsCustomer(User::factory()->create());
    expect($this->getJson('/api/v1/app-config')->assertOk()->json('data.intro'))->toBe($guest);
});

it('ships defaults that fit the slide in both languages and are the same words the app carries in its language files', function () {
    $arb = [];
    foreach (['en', 'ar'] as $lang) {
        $path = base_path("../mobile/lib/l10n/app_{$lang}.arb");
        expect(is_file($path))->toBeTrue();
        $arb[$lang] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
    foreach (IntroSlides::slideNumbers() as $n) {
        foreach (IntroSlides::PARTS as $part) {
            foreach (IntroSlides::LANGS as $lang) {
                $text = IntroSlides::default($n, $part, $lang);
                expect(str_replace("\u{00A0}", ' ', $text))->toBe(IntroSlides::clean($text), "{$n} {$part} {$lang} is plain text (a no-break space may keep the last two words together)")
                    ->and(IntroSlides::hasControlCharacters($text))->toBeFalse()
                    ->and(mb_strlen($text))->toBeGreaterThan(0)->toBeLessThanOrEqual(IntroSlides::max($part), "{$n} {$part} {$lang} fits its limit")
                    ->and($arb[$lang]['onboarding'.ucfirst($part).$n] ?? null)->toBe($text, "the app's {$lang} default of {$part} {$n} equals the dashboard's");
            }
        }
    }
});

it('has every new label and message in both languages, with the same placeholders', function () {
    $flat = fn (string $file, string $locale) => Arr::dot(Lang::get($file, [], $locale));
    foreach (['intro_admin'] as $file) {
        $en = $flat($file, 'en');
        $ar = $flat($file, 'ar');
        expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en), "{$file}: the same keys in both languages");
        foreach ($en as $key => $text) {
            expect($text)->toBeString()->not->toBe('')->and($ar[$key])->toBeString()->not->toBe('');
            preg_match_all('/:\w+/', $text, $a);
            preg_match_all('/:\w+/', $ar[$key], $b);
            expect($b[0])->toEqualCanonicalizing($a[0], "{$file}.{$key}: the same placeholders");
        }
    }
    foreach (IntroSlides::slideNumbers() as $n) {
        foreach (IntroSlides::PARTS as $part) {
            foreach (IntroSlides::LANGS as $lang) {
                $key = 'org_admin.setting.'.IntroSlides::field($n, $part, $lang);
                foreach (['en', 'ar'] as $locale) {
                    expect(Lang::has($key, $locale))->toBeTrue("{$key} ({$locale})")->and(trans($key, [], $locale))->not->toBe($key);
                }
                expect(trans($key, [], 'en'))->not->toBe(trans($key, [], 'ar'));
            }
        }
        foreach (['slide_art', 'slide_meaning'] as $group) {
            expect(Lang::has("intro_admin.{$group}.{$n}", 'en'))->toBeTrue()->and(Lang::has("intro_admin.{$group}.{$n}", 'ar'))->toBeTrue();
        }
    }
});

it('renders the settings page in Arabic as well', function () {
    app()->setLocale('ar');
    introPage()->assertSee(__('intro_admin.section_title', [], 'ar'))->assertSee('شاشات المقدمة');
});
