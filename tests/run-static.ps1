$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$utf8 = [System.Text.Encoding]::UTF8

function Read-Text($relative) {
    return [System.IO.File]::ReadAllText((Join-Path $root $relative), $utf8)
}

function Assert-True($condition, $message) {
    if (-not $condition) {
        throw "FAIL: $message"
    }
    Write-Host "OK: $message"
}

function Assert-Contains($relative, $needle, $message) {
    $text = Read-Text $relative
    Assert-True ($text.Contains($needle)) $message
}

function Assert-NotContains($relative, $needle, $message) {
    $text = Read-Text $relative
    Assert-True (-not $text.Contains($needle)) $message
}

Assert-True (Test-Path (Join-Path $root 'assets/vendor/pov-calendar/pov-calendar.js')) 'local calendar module exists'
Assert-True ((Get-Item (Join-Path $root 'assets/brand/pro-ocean-logo-blue.svg')).Length -gt 50000) 'supplied logo SVG is installed'
Assert-True ((Get-Item (Join-Path $root 'assets/brand/pro-ocean-symbol-blue.svg')).Length -gt 50000) 'supplied symbol SVG is installed'

Assert-Contains 'assets/dist/frontend.js' 'new window.POVCalendar' 'frontend uses bundled calendar module'
Assert-Contains 'assets/dist/frontend.js' 'async function checkRoute' 'frontend checks route suggestions on demand'
Assert-Contains 'assets/dist/frontend.js' 'requestTimeoutMs' 'frontend stops an unresponsive route request'
Assert-Contains 'assets/dist/frontend.js' ').slice(0, 2)' 'frontend shows exactly two route dates'
Assert-Contains 'assets/dist/frontend.js' 'firstSuggestedDate.getDate() + 14' 'frontend hides recommendations with less than two weeks lead time'
Assert-Contains 'assets/dist/frontend.js' "Intl.DateTimeFormat('de-DE'" 'frontend formats visible dates in German'
Assert-Contains 'assets/dist/frontend.js' 'copyRouteRegionToForm' 'editable booking details reuse postal code and state'
Assert-NotContains 'assets/dist/frontend.js' 'reason_summary' 'frontend omits route comments'
Assert-Contains 'templates/frontend-booking.php' 'Hol dir das Meer zu dir' 'frontend uses the requested sea headline'
Assert-Contains 'templates/frontend-booking.php' 'data-action="toggle-calendar"' 'frontend offers the calendar alternative'
Assert-Contains 'templates/frontend-booking.php' 'data-action="toggle-range"' 'frontend offers the date-range alternative'
Assert-Contains 'templates/frontend-booking.php' 'name="postal_code"' 'postal code stays editable in the form'
Assert-Contains 'templates/frontend-booking.php' 'name="state_code"' 'state stays editable in the form'
Assert-Contains 'assets/vendor/pov-calendar/pov-calendar.js' 'dataset.weekend' 'calendar exposes weekend days for styling and semantics'
Assert-Contains 'assets/vendor/pov-calendar/pov-calendar.js' 'dataset.date = date' 'calendar exposes exact dates for reliable interaction'
Assert-Contains 'assets/vendor/pov-calendar/pov-calendar.js' "Intl.DateTimeFormat('de-DE'" 'calendar formats dates in German'
Assert-Contains 'templates/frontend-booking.php' 'Buchbar' 'frontend legend explains bookable days'
Assert-Contains 'templates/frontend-booking.php' 'Auf Anfrage' 'frontend legend explains request-only days'
Assert-Contains 'templates/frontend-booking.php' 'Nicht buchbar' 'frontend legend uses clear unavailable wording'
Assert-NotContains 'templates/frontend-booking.php' 'Eingeschränkt' 'frontend no longer uses unclear limited wording'
Assert-NotContains 'src/Admin/Menu.php' 'mapPanel' 'admin contains no map panels'
Assert-NotContains 'assets/dist/admin.js' 'POVLeaflet' 'admin JavaScript contains no map renderer'
Assert-NotContains 'src/Admin/Menu.php' 'pov-dashboard' 'admin starts directly in the request inbox'
Assert-Contains 'src/Admin/Menu.php' 'pov_send_response' 'admin provides one response workflow'
Assert-Contains 'src/Admin/Menu.php' "'accept' => 'Zusage'" 'admin exposes acceptance'
Assert-Contains 'src/Admin/Menu.php' "'question' =>" 'admin exposes questions'
Assert-Contains 'src/Admin/Menu.php' "'reject' => 'Absage'" 'admin exposes rejection'
Assert-Contains 'src/Admin/Menu.php' 'pov_team_notification_emails' 'team notification addresses are configurable'
Assert-Contains 'src/Admin/Menu.php' 'pov-statistics' 'admin exposes route and cost statistics'
Assert-Contains 'src/Service/StatisticsService.php' 'personnel_cost' 'statistics include personnel costs'
Assert-Contains 'src/Admin/Menu.php' 'pov_personnel_hourly_rate' 'personnel rates are editable'
Assert-Contains 'src/Service/RouteOptimizationService.php' 'route_duration_minutes' 'route planning exposes estimated driving time'
Assert-Contains 'src/Service/WeeklyClusterService.php' 'overnightRecommendations' 'weekly tours recommend overnight regions'
Assert-Contains 'src/Admin/Menu.php' 'pov-tour-timeline' 'weekly tours render as an ordered timeline'
Assert-Contains 'src/Admin/Menu.php' 'pov_response_accept_template' 'request responses use editable templates'
Assert-Contains 'assets/dist/admin.js' 'automaticMessage' 'response templates switch with the response type'
Assert-Contains 'src/Admin/Menu.php' 'pov_test_geo' 'geo providers can be tested from settings'
Assert-Contains 'src/Admin/Menu.php' 'Geocoding und Fahrzeitmatrix' 'geo test covers address and routing analysis'
Assert-Contains 'src/Admin/Menu.php' 'pov_save_request_address' 'request addresses can be corrected in the backend'
Assert-Contains 'src/Admin/Menu.php' 'pov_retry_missing_addresses' 'missing addresses can be checked again in one action'
Assert-Contains 'src/Repository/AppointmentRepository.php' 'syncAddressFromRequest' 'corrected addresses propagate to confirmed appointments'
Assert-Contains 'assets/dist/admin.js' 'bindProviderSettings' 'routing settings hide fields that do not belong to the selected provider'
Assert-Contains 'src/Routing/PeliasGeocodingProvider.php' "'country' => 'Germany'" 'HeiGIT structured geocoding uses the accepted country value'
Assert-Contains 'src/Routing/PeliasGeocodingProvider.php' "wp_get_environment_type() === 'local'" 'local SSL workaround stays limited to the local environment'
Assert-Contains 'src/Admin/Menu.php' 'calendar_date_from' 'admin calendar supports start date'
Assert-Contains 'src/Admin/Menu.php' 'calendar_date_to' 'admin calendar supports end date'
Assert-Contains 'src/Admin/Menu.php' 'custom_start_postal_code' 'admin calendar supports start point postal code'
Assert-Contains 'src/Admin/Menu.php' 'resolveCalendarStartPoint' 'admin calendar resolves start point'
Assert-Contains 'src/Admin/Menu.php' 'ProviderFactory())->geocoding()' 'admin calendar uses configured geocoding provider'
Assert-Contains 'src/Admin/Menu.php' 'adminCalendarGrid' 'admin calendar renders a real month grid'
Assert-Contains 'src/Admin/Menu.php' 'adminCalendarEvents' 'admin calendar aggregates operational events'
Assert-Contains 'src/Admin/Menu.php' 'dateAllowedForRequest' 'appointment confirmation is constrained to request dates'
Assert-Contains 'src/Admin/Menu.php' 'min="' 'appointment confirmation date has min constraint'
Assert-Contains 'src/Admin/Menu.php' 'max="' 'appointment confirmation date has max constraint'
Assert-Contains 'assets/dist/admin.js' 'syncDateRange' 'admin date pickers are dependency-sensitive'
Assert-Contains 'assets/dist/admin.js' 'end.min = start.value' 'end date cannot be before start date'
Assert-Contains 'src/Repository/CalendarDayRepository.php' 'upsertRange' 'calendar repository saves date ranges'
Assert-Contains 'src/Service/AvailabilityService.php' "format('N') >= 6" 'public calendar marks weekends separately'
Assert-Contains 'src/Service/AvailabilityService.php' 'is_weekend' 'public calendar exposes weekend metadata'
Assert-Contains 'src/Service/AvailabilityService.php' "'public_state' => 'past'" 'past calendar days have a dedicated state'
Assert-Contains 'assets/dist/frontend.css' '.pov-day[data-state="past"]' 'past calendar days are styled in grey'
Assert-Contains 'src/Service/AvailabilityService.php' 'Auf Anfrage' 'public calendar labels limited days as request-only'
Assert-Contains 'src/Service/PublicRecommendationService.php' "format('N') >= 6" 'public recommendations skip weekends'
Assert-Contains 'src/Service/PublicRecommendationService.php' 'compareDatedInsertion' 'public recommendations preserve dated tour order'
Assert-Contains 'src/Service/PublicRecommendationService.php' 'MIN_RECOMMENDATION_LEAD_DAYS = 14' 'public recommendations start at least two weeks ahead'
Assert-Contains 'src/Service/PublicRecommendationService.php' 'PostalCodeService' 'postal codes are resolved to a locality before geocoding'
Assert-Contains 'src/Service/PublicRecommendationService.php' "'direct'" 'weeks without tour stops reuse one direct-route calculation'
Assert-Contains 'src/Service/PublicRecommendationService.php' 'array_slice($suggestions, 0, 2)' 'recommendation API returns two best route dates'
Assert-Contains 'src/Service/PublicRecommendationService.php' 'weekSuggestions' 'public recommendations group good days into weeks'
Assert-Contains 'src/Service/PublicRecommendationService.php' 'PlanningSignalService' 'public recommendations combine routing with weekly and regional demand'
Assert-Contains 'src/Service/PlanningSignalService.php' 'WEEK_FILLING' 'planning signals prioritize filling active weeks'
Assert-Contains 'src/Service/PlanningSignalService.php' 'SAME_STATE_CLUSTER' 'planning signals aggregate requests by federal state'
Assert-Contains 'src/Service/PlanningSignalService.php' 'STATE_BLOCK_CONFLICT' 'multi-state planning avoids mixing unrelated regional blocks'
Assert-Contains 'src/Repository/RequestRepository.php' 'planned_suggestion_date' 'existing suggestions anchor flexible demand to one preferred week'
Assert-NotContains 'assets/dist/frontend.js' 'pov-suggestion-metrics' 'public suggestions omit distance and savings metrics'
Assert-Contains 'src/Service/RequestRoutingService.php' '_server_latitude' 'requests persist only server-side geocoding results'
Assert-Contains 'src/Service/RouteOptimizationService.php' 'calculateMatrix' 'route optimization uses the routing matrix'
Assert-Contains 'src/Service/RouteOptimizationService.php' 'compareInsertion' 'route optimization calculates incremental insertion costs'
Assert-Contains 'src/Service/RouteOptimizationService.php' 'return_route' 'dated suggestions extend the final return leg'
Assert-Contains 'src/Service/RouteOptimizationService.php' 'nearestNeighbour' 'weekly routes start from a deterministic nearest-neighbour plan'
Assert-Contains 'src/Service/RouteOptimizationService.php' 'improveOrder' 'weekly routes are locally improved after the initial plan'
Assert-Contains 'src/Service/RouteOptimizationService.php' 'optimizeWithFixedOrder' 'confirmed dates keep their sequence while flexible stops are inserted'
Assert-Contains 'src/Service/RouteOptimizationService.php' 'routeLegs' 'route planning exposes distances between appointments'
Assert-Contains 'src/Service/InternalSuggestionService.php' 'incremental_cost' 'internal suggestions rank real incremental route cost'
Assert-Contains 'src/Service/InternalSuggestionService.php' 'PlanningSignalService' 'internal suggestions also fill weeks and regional clusters'
Assert-Contains 'src/Service/WeeklyClusterService.php' 'cost_saved' 'weekly planning exposes estimated cost savings'
Assert-Contains 'src/Service/WeeklyClusterService.php' 'separateRequestStates' 'tour planning keeps multi-state requests in regional blocks'
Assert-Contains 'src/Service/WeeklyClusterService.php' 'state_codes' 'tour headers expose their federal-state grouping'
Assert-Contains 'src/Service/DemoDataService.php' 'POV_DEMO_DATA:' 'demo seed uses stable request markers'
Assert-Contains 'src/Service/DemoDataService.php' '_server_latitude' 'demo seed injects known server-side coordinates'
Assert-NotContains 'src/Service/DemoDataService.php' 'RequestRoutingService' 'demo seed never invokes external geocoding'
Assert-Contains 'tests/seed-local-demo-data.php' "in_array('--seed'" 'demo seed requires an explicit CLI flag'
Assert-Contains 'tests/seed-local-demo-data.php' "str_ends_with(`$homeHost, '.local')" 'demo seed is restricted to local WordPress hosts'
Assert-Contains 'playground/blueprint.template.json' '"resource": "url"' 'Playground demo installs the compact release archive'
Assert-Contains 'playground/blueprint.template.json' "update_option('pov_geocoding_provider', 'demo')" 'Playground geocoding is deterministic and network independent'
Assert-Contains 'playground/blueprint.template.json' "update_option('pov_routing_provider', 'null')" 'Playground routing uses the instant geographic fallback'
Assert-Contains 'playground/index.html' 'configured.login = isBackend' 'public booking skips the slow administrator login step'
Assert-Contains 'playground/blueprint.template.json' 'DemoDataService' 'Playground demo creates safe example records'
Assert-Contains 'playground/blueprint.template.json' 'pre_wp_mail' 'Playground demo disables outgoing mail'
Assert-Contains 'playground/index.html' 'https://playground.wordpress.net/' 'public launcher opens WordPress Playground'
Assert-Contains '.github/workflows/pages.yml' 'actions/deploy-pages@v4' 'GitHub Pages deployment is automated'
Assert-Contains 'src/Database/Schema.php' 'sort_order INT UNSIGNED' 'suggestion ordering avoids the reserved SQL rank keyword'
Assert-NotContains 'src/Database/Schema.php' 'rank INT UNSIGNED' 'suggestion schema no longer uses a reserved SQL keyword'
Assert-Contains 'src/Service/MailService.php' 'renderProposalHtml' 'proposal mails render HTML buttons'
Assert-Contains 'src/Service/IcsService.php' 'calendar.google.com/calendar/render' 'confirmed appointments expose a Google Calendar template link'
Assert-Contains 'src/Admin/Menu.php' 'In Google Kalender' 'calendar view offers the Google Calendar export action'
Assert-Contains 'src/Service/MailService.php' 'sendTeamNotification' 'new requests notify configured team members'
Assert-Contains 'src/Service/MailService.php' 'sendResponse' 'team can send acceptance, rejection, and questions'
Assert-Contains 'src/Service/MailService.php' 'acceptUrl' 'proposal mails include public acceptance links'
Assert-Contains 'src/Service/MailService.php' 'Content-Type: text/html; charset=UTF-8' 'proposal mails are sent as HTML'
Assert-Contains 'src/Service/PublicSuggestionResponseService.php' 'admin_post_nopriv_pov_accept_suggestion' 'public proposal acceptance works without login'
Assert-Contains 'src/Service/PublicSuggestionResponseService.php' 'hash_hmac' 'public proposal acceptance links are signed'
Assert-Contains 'src/Repository/SuggestionRepository.php' 'acceptPublicChoice' 'accepted public proposal expires alternatives'
Assert-Contains 'src/Plugin.php' 'PublicSuggestionResponseService' 'public proposal acceptance service is registered'
Assert-Contains 'src/Service/IcsService.php' 'CONTACT:' 'ICS files include contact details'
Assert-Contains 'src/Service/IcsService.php' 'ORGANIZER;CN=' 'ICS files include organizer e-mail when present'
Assert-Contains 'src/Service/IcsService.php' 'Telefon: ' 'calendar exports include contact phone'
Assert-Contains 'src/Service/IcsService.php' 'E-Mail: ' 'calendar exports include contact e-mail'
Assert-Contains 'src/Routing/OsrmRoutingProvider.php' 'table/v1/driving' 'OSRM matrix endpoint is implemented'
Assert-Contains 'src/Routing/OsrmRoutingProvider.php' "'annotations' => 'distance,duration'" 'OSRM matrix requests distances and durations'
Assert-Contains 'src/Routing/OsrmRoutingProvider.php' 'coordinatePathPart' 'OSRM coordinates are formatted without encoded comma'
Assert-NotContains 'src/Routing/OsrmRoutingProvider.php' 'rawurlencode' 'OSRM coordinate path does not encode commas'
Assert-NotContains 'src/Routing/OsrmRoutingProvider.php' 'Matrix ist im MVP als austauschbarer Provider vorbereitet' 'matrix stub was removed'

$visibleFiles = @(
    'templates/frontend-booking.php',
    'assets/dist/frontend.js',
    'src/Activation.php',
    'src/Admin/Menu.php',
    'src/Domain/RequestStatus.php',
    'src/Domain/WorkState.php',
    'src/Domain/CalendarState.php',
    'docs/acceptance-test.md',
    'docs/assumptions.md'
)

$badWords = @(
    'Tuebingen', 'Wuerttemberg', 'Thueringen', 'fuer ', 'Fuer ', 'pruef',
    'moeglich', 'waehle', 'Schueler', 'Rueckmeldung', 'Gruesse',
    'Bestaetigt', 'verfuegbar', 'Eingeschraenkt', 'oeffentlich',
    'Vorschlaege', 'Datenschutzerklaerung', 'Foerderschule', 'Strasse'
)

foreach ($file in $visibleFiles) {
    $text = Read-Text $file
    foreach ($word in $badWords) {
        Assert-True (-not $text.Contains($word)) "visible text in $file has no '$word'"
    }
}

Write-Host 'Static checks passed.'
