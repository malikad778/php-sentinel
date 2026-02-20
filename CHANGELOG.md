# Changelog

All notable changes to `php-sentinel` will be documented in this file.

## [1.0.0] - 2026-02-20
### Added
- Core `InferenceEngine` to reverse engineer JSON structural schemas from arrays
- `TypeResolver`, `FormatHintDetector`, `EnumCandidateDetector` for deep parsing mapping
- `DriftDetector` and `SchemaDrift` to find JSON Object differencing over time. 
- Dispatches `SchemaDriftDetected`, `SampleCollected`, and `SchemaHardened` PSR-14 events.
- Added File, Redis, PDO, and Array `SchemaStore` drivers
- `SampleAccumulator` and multi-sample schema merging algorithms to handle probabilities!
- Built Symfony Console CLI profiling tool.
- Laravel `SentinelServiceProvider` adapter macro.
