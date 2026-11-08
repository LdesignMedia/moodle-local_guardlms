# Changelog

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
