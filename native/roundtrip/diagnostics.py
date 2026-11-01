"""Shared-policy native log classifier. Unknown nonempty output fails closed."""
import re
import startup_watchdog as startup


def classify_logs(logs, policy, *, startup_evidence=None, image=None):
    if policy.get('schema') != 'civilizationbot.native-diagnostics.v1' or policy.get('unknown_nonempty_output') != 'reject' or policy.get('max_log_bytes') != 4096 or policy.get('max_lines') != 128:
        raise ValueError('native_packet_diagnostic_policy_invalid')
    try:
        fatal = [re.compile(pattern, re.IGNORECASE) for pattern in policy['fatal_patterns']]
        benign = [re.compile(pattern, re.IGNORECASE) for pattern in policy['known_harmless_patterns']]
    except (KeyError, TypeError, re.error) as error:
        raise ValueError('native_packet_diagnostic_policy_invalid') from error
    if not fatal or not benign:
        raise ValueError('native_packet_diagnostic_policy_invalid')
    conditional = [f'World opened on network port {startup.PORT} for address {startup.ADDRESS}.',
                   'Welcome BYOND! (5.0 Public Version 516.1688)',
                   'World no longer opened to new users.']
    if startup_evidence is not None:
        startup.validate_evidence(startup_evidence, image)
        if set(logs) != {'stdout', 'stderr', 'native.log'}:
            raise ValueError('native_packet_diagnostic_evidence_invalid')
    counts = {'empty_lines': 0, 'known_harmless_lines': 0}
    for record in logs.values():
        if not isinstance(record, dict) or not isinstance(record.get('text'), str) or record.get('truncated') is not False or len(record['text'].encode('utf-8')) > 4096:
            raise ValueError('native_packet_diagnostic_evidence_invalid')
        lines = record['text'].split('\n')
        if len(lines) > 128:
            raise ValueError('native_packet_diagnostic_evidence_invalid')
        for raw in lines:
            line = raw.strip(' \t\r')
            if any(pattern.search(line) for pattern in fatal):
                raise ValueError('native_packet_diagnostic_error')
            if not line:
                counts['empty_lines'] += 1
            elif any(pattern.fullmatch(line) for pattern in benign):
                counts['known_harmless_lines'] += 1
            elif startup_evidence is not None and line in conditional:
                counts['known_harmless_lines'] += 1
            else:
                raise ValueError('native_packet_unclassified_output')
    if startup_evidence is not None:
        # Strict conditional startup transcript: no arbitrary numeric ports,
        # duplicate lines, missing banners or output moved to another stream.
        if logs['stdout']['text'] or logs['stderr']['text'] or logs['native.log']['text'].splitlines() != conditional:
            raise ValueError('native_packet_startup_transcript_mismatch')
    return {'status': 'accepted', **counts}
