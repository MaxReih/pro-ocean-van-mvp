param(
    [string] $Endpoint = 'http://127.0.0.1:9351',
    [string] $BaseUrl = 'http://proocean.local',
    [string] $OutputDir = ''
)

$ErrorActionPreference = 'Stop'

if ($OutputDir -eq '') {
    $OutputDir = Join-Path (Split-Path -Parent $PSScriptRoot) 'artifacts\browser-qa'
}

New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null

$targets = Invoke-RestMethod -Uri ($Endpoint.TrimEnd('/') + '/json/list') -TimeoutSec 15
$target = $targets | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
if (-not $target) {
    throw 'Kein Browser-Tab gefunden.'
}

$socket = [System.Net.WebSockets.ClientWebSocket]::new()
$connectTimeout = [System.Threading.CancellationTokenSource]::new(15000)
$socket.ConnectAsync([Uri] $target.webSocketDebuggerUrl, $connectTimeout.Token).GetAwaiter().GetResult() | Out-Null
$script:sequence = 0

function Invoke-Cdp {
    param(
        [Parameter(Mandatory)] [string] $Method,
        [hashtable] $Params = @{}
    )

    $id = ++$script:sequence
    $json = @{ id = $id; method = $Method; params = $Params } | ConvertTo-Json -Depth 20 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $segment = [System.ArraySegment[byte]]::new($bytes)
    $socket.SendAsync($segment, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).GetAwaiter().GetResult()

    do {
        $stream = [IO.MemoryStream]::new()
        do {
            $buffer = [byte[]]::new(65536)
            $receiveSegment = [System.ArraySegment[byte]]::new($buffer)
            $result = $socket.ReceiveAsync($receiveSegment, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
            if ($result.Count -gt 0) {
                $stream.Write($buffer, 0, $result.Count)
            }
        } while (-not $result.EndOfMessage)

        $message = [Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
        $stream.Dispose()
    } while ($message.id -ne $id)

    if ($message.error) {
        throw ($message.error | ConvertTo-Json -Compress)
    }

    return $message.result
}

function Invoke-BrowserExpression {
    param([Parameter(Mandatory)] [string] $Expression)

    $response = Invoke-Cdp -Method 'Runtime.evaluate' -Params @{
        expression = $Expression
        awaitPromise = $true
        returnByValue = $true
    }
    if ($response.exceptionDetails) {
        throw ($response.exceptionDetails | ConvertTo-Json -Depth 10 -Compress)
    }
    return $response.result.value
}

function Wait-BrowserExpression {
    param(
        [Parameter(Mandatory)] [string] $Expression,
        [int] $TimeoutMilliseconds = 30000
    )

    $started = [DateTime]::UtcNow
    do {
        if (Invoke-BrowserExpression -Expression $Expression) {
            return
        }
        Start-Sleep -Milliseconds 200
    } while (([DateTime]::UtcNow - $started).TotalMilliseconds -lt $TimeoutMilliseconds)

    throw "Zeitueberschreitung: $Expression"
}

function Save-BrowserScreenshot {
    param([Parameter(Mandatory)] [string] $FileName)

    $capture = Invoke-Cdp -Method 'Page.captureScreenshot' -Params @{
        format = 'png'
        fromSurface = $true
        captureBeyondViewport = $false
    }
    [IO.File]::WriteAllBytes((Join-Path $OutputDir $FileName), [Convert]::FromBase64String($capture.data))
}

Invoke-Cdp -Method 'Page.enable' | Out-Null
Invoke-Cdp -Method 'Runtime.enable' | Out-Null
Invoke-Cdp -Method 'Emulation.setDeviceMetricsOverride' -Params @{
    width = 1440
    height = 1100
    deviceScaleFactor = 1
    mobile = $false
} | Out-Null
Invoke-Cdp -Method 'Page.navigate' -Params @{ url = ($BaseUrl.TrimEnd('/') + '/planer/') } | Out-Null
Wait-BrowserExpression -Expression 'document.readyState === "complete"'
Wait-BrowserExpression -Expression 'document.querySelector("[data-role=calendar]")?.getAttribute("aria-busy") === "false"'

$initial = Invoke-BrowserExpression -Expression @'
(() => ({
    headline: document.querySelector('.pov-hero h1')?.textContent.trim(),
    calendarVisible: !document.querySelector('[data-role="calendar-panel"]')?.hidden,
    availableLabel: Array.from(document.querySelectorAll('.pov-legend span')).some((item) => item.textContent.includes('Noch verf\u00fcgbar')),
    publicEventLabel: Array.from(document.querySelectorAll('.pov-legend span')).some((item) => item.textContent.includes('\u00d6ffentliches Event'))
}))()
'@

if ($initial.headline -ne 'Hol das Meer zu dir' -or -not $initial.calendarVisible -or -not $initial.availableLabel -or -not $initial.publicEventLabel) {
    throw "Kalenderpruefung fehlgeschlagen: $($initial | ConvertTo-Json -Compress)"
}

Invoke-BrowserExpression -Expression @'
(() => {
    const state = document.querySelector('[data-field="state_code"]');
    const postal = document.querySelector('[data-field="postal_code"]');
    state.value = 'BW';
    state.dispatchEvent(new Event('change', { bubbles: true }));
    postal.value = '72072';
    postal.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('[data-action="check-route"]').click();
    return true;
})()
'@ | Out-Null
Wait-BrowserExpression -Expression 'document.querySelector("[data-action=check-route]")?.getAttribute("aria-busy") === "false"' -TimeoutMilliseconds 30000

Invoke-BrowserExpression -Expression @'
(() => {
    document.querySelector('[data-action="toggle-range"]').click();
    document.querySelector('[data-range-field="from"]').value = '2026-08-26';
    document.querySelector('[data-range-field="to"]').value = '2026-09-02';
    document.querySelectorAll('[data-range-weekday]').forEach((field) => field.checked = true);
    document.querySelector('[data-action="select-range"]').click();
    return true;
})()
'@ | Out-Null
Wait-BrowserExpression -Expression '!document.querySelector("[data-role=form]")?.hidden'

Invoke-BrowserExpression -Expression @'
(() => {
    const form = document.querySelector('[data-role="form"]');
    const values = {
        institution_name: 'QA Meeresschule',
        institution_type: 'Schule',
        contact_first_name: 'Mara',
        contact_last_name: 'Test',
        contact_email: 'qa@example.test',
        contact_phone: '07071123456',
        contact_role: 'Lehrkraft',
        institution_website: 'https://example.test',
        street: 'Musterstra\u00dfe',
        house_number: '12',
        city: 'T\u00fcbingen',
        postal_code: '72072',
        state_code: 'BW',
        school_grade: 'grade_5',
        school_class_count: '2',
        school_teachers_per_class: '1',
        school_children_per_class: '20',
        school_adult_count: '4',
        school_needs: 'Zwei Klassen, barrierefreier Zugang erforderlich.'
    };
    Object.entries(values).forEach(([name, value]) => {
        const field = form.elements[name];
        field.value = value;
        field.dispatchEvent(new Event('change', { bubbles: true }));
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
    document.querySelector('[data-event-section="Schule"]').scrollIntoView({ block: 'center' });
    return true;
})()
'@ | Out-Null

$school = Invoke-BrowserExpression -Expression @'
(() => ({
    schoolVisible: !document.querySelector('[data-event-section="Schule"]')?.hidden,
    eventHidden: document.querySelector('[data-event-section="Veranstaltung"]')?.hidden,
    otherHidden: document.querySelector('[data-event-section="Sonstiges"]')?.hidden,
    participantTotal: document.querySelector('[name="participant_total"]')?.value
}))()
'@
if (-not $school.schoolVisible -or -not $school.eventHidden -or -not $school.otherHidden -or $school.participantTotal -ne '44') {
    throw "Schuldynamik fehlgeschlagen: $($school | ConvertTo-Json -Compress)"
}
Save-BrowserScreenshot -FileName 'booking-school-fields.png'

Invoke-BrowserExpression -Expression @'
(() => {
    const field = document.querySelector('[name="institution_type"]');
    field.value = 'Veranstaltung';
    field.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
})()
'@ | Out-Null
$event = Invoke-BrowserExpression -Expression '(() => ({eventVisible: !document.querySelector("[data-event-section=Veranstaltung]")?.hidden, schoolHidden: document.querySelector("[data-event-section=Schule]")?.hidden, ageFieldEnabled: !document.querySelector("[name=event_child_age_range]")?.disabled}))()'
if (-not $event.eventVisible -or -not $event.schoolHidden -or -not $event.ageFieldEnabled) {
    throw "Veranstaltungsdynamik fehlgeschlagen: $($event | ConvertTo-Json -Compress)"
}

Invoke-BrowserExpression -Expression @'
(() => {
    const field = document.querySelector('[name="institution_type"]');
    field.value = 'Sonstiges';
    field.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
})()
'@ | Out-Null
$other = Invoke-BrowserExpression -Expression '(() => ({otherVisible: !document.querySelector("[data-event-section=Sonstiges]")?.hidden, occasionEnabled: !document.querySelector("[name=occasion_description]")?.disabled}))()'
if (-not $other.otherVisible -or -not $other.occasionEnabled) {
    throw "Sonstiges-Dynamik fehlgeschlagen: $($other | ConvertTo-Json -Compress)"
}

Invoke-BrowserExpression -Expression @'
(() => {
    const form = document.querySelector('[data-role="form"]');
    const type = form.elements.institution_type;
    type.value = 'Schule';
    type.dispatchEvent(new Event('change', { bubbles: true }));
    form.querySelector('[data-next]').click();
    return true;
})()
'@ | Out-Null
Wait-BrowserExpression -Expression '!document.querySelector("[data-form-step=\"2\"]")?.hidden'

Invoke-BrowserExpression -Expression @'
(() => {
    const form = document.querySelector('[data-role="form"]');
    form.elements.availability_window.value = 'full_day';
    form.elements.availability_window.dispatchEvent(new Event('change', { bubbles: true }));
    form.elements.school_schedule_notes.value = '2.\u20135. Stunde, Pause 10:15\u201310:45 Uhr';
    document.querySelector('.pov-detail-block').scrollIntoView({ block: 'center' });
    return true;
})()
'@ | Out-Null
Save-BrowserScreenshot -FileName 'booking-time-fields.png'

Invoke-BrowserExpression -Expression @'
(() => {
    const form = document.querySelector('[data-role="form"]');
    form.elements.venue_type.value = 'both';
    form.elements.venue_type.dispatchEvent(new Event('change', { bubbles: true }));
    form.elements.indoor_room_description.value = 'Gro\u00dfer, ebener Mehrzweckraum im Erdgeschoss.';
    form.elements.outdoor_area_description.value = 'Befestigter Schulhof mit direkter Zufahrt.';
    const parking = form.querySelector('[name="parking_available"][value="yes"]');
    parking.checked = true;
    parking.dispatchEvent(new Event('change', { bubbles: true }));
    form.elements.parking_type.value = 'schoolyard';
    form.elements.parking_location.value = 'Musterstra\u00dfe 12, 72072 T\u00fcbingen';
    document.querySelector('.pov-parking-block').scrollIntoView({ block: 'center' });
    return true;
})()
'@ | Out-Null

$details = Invoke-BrowserExpression -Expression @'
(() => ({
    timeWindowVisible: Boolean(document.querySelector('[name="availability_window"]')?.offsetParent),
    schoolScheduleVisible: !document.querySelector('[name="school_schedule_notes"]')?.closest('[data-event-section]')?.hidden,
    indoorVisible: !document.querySelector('[data-venue-section="indoor"]')?.hidden,
    outdoorVisible: !document.querySelector('[data-venue-section="outdoor"]')?.hidden,
    parkingVisible: !document.querySelector('[data-parking-details]')?.hidden,
    schoolyard: Boolean(document.querySelector('[name="parking_type"] option[value="schoolyard"]')),
    dimensions: document.querySelector('.pov-parking-block')?.textContent.includes('6 m lang'),
    unclearChoices: Array.from(document.querySelectorAll('.pov-questions span')).filter((item) => item.textContent.trim() === 'Unklar').length
}))()
'@
if (-not $details.timeWindowVisible -or -not $details.schoolScheduleVisible -or -not $details.indoorVisible -or -not $details.outdoorVisible -or -not $details.parkingVisible -or -not $details.schoolyard -or -not $details.dimensions -or $details.unclearChoices -ne 0) {
    throw "Detaildynamik fehlgeschlagen: $($details | ConvertTo-Json -Compress)"
}
Save-BrowserScreenshot -FileName 'booking-onsite-fields.png'

$socket.Dispose()

[pscustomobject]@{
    headline = $initial.headline
    calendar = 'direkt sichtbar'
    schoolParticipants = $school.participantTotal
    eventSection = 'dynamisch sichtbar'
    otherSection = 'dynamisch sichtbar'
    onsiteFields = 'dynamisch sichtbar'
    unclearChoices = $details.unclearChoices
} | ConvertTo-Json -Compress
