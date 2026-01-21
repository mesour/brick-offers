# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Analysis REST API** - New endpoints for accessing analysis data, trends, and benchmarks
  - `GET /api/leads/{id}/analyses` - Analysis history for a lead
  - `GET /api/leads/{id}/trend` - Trending data (snapshots) with period filter
  - `GET /api/leads/{id}/benchmark` - Compare lead with industry benchmark
  - `GET /api/industries` - List all industries with benchmark status
  - `GET /api/industries/{industry}/benchmark` - Industry benchmark details
  - `GET /api/industries/{industry}/benchmark/history` - Benchmark history
  - `LeadAnalysisController` and `IndustryBenchmarkController` implementations

- **Analysis Archive Command** - New command for archiving old analysis data (Fáze 6)
  - Retention policy: compress (30-90 days), clear rawData (90-365 days), delete (365+ days)
  - `app:analysis:archive` command with dry-run and show-counts options
  - `ArchiveService` with batch processing to avoid OOM
  - Compression uses gzip + base64 for safe JSON storage
  - Documentation for cron setup (monthly archivation)

- **Reference Crawler Discovery Source** - New discovery source that finds client websites from agency portfolios
  - Uses existing sources (Google, Seznam, Firmy.cz) as inner source to find agencies
  - Crawls agency websites to find reference/portfolio pages
  - Extracts client website URLs from those pages
  - New enum value `REFERENCE_CRAWLER` in `LeadSource`
  - New `--inner-source` option in `LeadDiscoverCommand`
  - Comprehensive domain filtering (social networks, stock photos, CMS sites, etc.)
  - URL truncation to handle long URLs (500 char DB limit)
