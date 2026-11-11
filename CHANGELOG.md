# Changelog

## 1.12.0

- Forward bounded enrolment-method enablement/usage and configured external executable metadata for reviewed CVE prerequisites, without exposing paths or personal records.

- Add metadata-only PCNTL presence and FFI configuration observations for optional web-runtime hardening advisories; preserve preload and unknown states.

- Report runtime-scoped phpinfo availability without executing the function or exporting PHP configuration. Requires the GuardLMS receiver for issue #2935 before upgrading. CLI pushes do not establish web-runtime availability.

## 1.11.0 — 2026-11-08

- Report bundled core and plugin dependency metadata through the authenticated
  inventory channel, including package locations and coverage limitations.
- Synchronize monitoring SDK release metadata and advance the plugin build after 1.10.1.

## 1.10.1 — 2026-11-08

- Include the CAMP listing manifest and automated release workflow in the tagged
  source so CAMP can independently rebuild and verify this release for distribution.
- Exclude development files and registry metadata from distribution ZIPs through
  `.gitattributes`.
- Synchronize the plugin and monitoring SDK release metadata to 1.10.1.

The plugin retains its alpha maturity and existing Moodle compatibility. This
release introduces no database migrations or changes to monitoring behavior.
