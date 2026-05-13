# Changelog

All notable changes to `graystackit/laravel-mollie-billing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- CI: allow manual workflow runs via `workflow_dispatch`.
- Drop Laravel 11 support — `elegantly/laravel-invoices ^4.8` requires Laravel 12+. Composer constraint narrowed to `^12.0|^13.0`.
- `livewire/flux-pro` moved from `require` to `suggest`. The consuming application must install it separately with its own commercial license; this package no longer attempts to pull it from the private Flux repository.

## [0.1.0] - 2026-05-13

Initial public release.
