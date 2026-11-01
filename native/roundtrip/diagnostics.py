"""Shared-policy native log classifier. Unknown nonempty output fails closed."""
import re


def classify_logs(logs, policy):
    if policy.get('schema') != 'civilizationbot.native-diagnostics.v1' or policy.get('unknown_nonempty_output') != 'reject' or policy.get('max_log_bytes') != 4096 or policy.get('max_lines') != 128:
        raise ValueError('native_packet_diagnostic_policy_invalid')
    try:
        fatal = [re.compile(pattern, re.IGNORECASE) for pattern in policy['fatal_patterns']]
        benign = [re.compile(pattern, re.IGNORECASE) for pattern in policy['known_harmless_patterns']]
    except (KeyError, TypeError, re.error) as error:
        raise ValueError('native_packet_diagnostic_policy_invalid') from error
    if not fatal or not benign:
        raise ValueError('native_packet_diagnostic_policy_invalid')
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
            else:
                raise ValueError('native_packet_unclassified_output')
    return {'status': 'accepted', **counts}
