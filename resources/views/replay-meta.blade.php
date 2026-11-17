@php($watchtowerReplay = \Phattarachai\WatchtowerLaravel\Support\ReplayConfig::forRequest(request()))
@if ($watchtowerReplay !== null)
<meta name="watchtower-replay" content="{{ json_encode($watchtowerReplay) }}">
@endif
