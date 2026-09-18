# Lyralink iPhone App

This folder contains the iPhone-ready Expo/React Native client for Lyralink.

## Features
- Secure token-based login against the existing PHP backend
- Chat screen connected to /api/chat.php
- Account screen with logout
- Legal/App Store links for privacy, terms, and support

## Run locally
1. Install dependencies
2. Start Expo
3. Open in the iOS simulator or Expo Go

Suggested commands:
- npm install
- npm run start
- npm run ios

## Required backend support
The app expects:
- mobile token issuance from /api/auth.php
- bearer token support on chat and billing endpoints
- privacy policy at /pages/privacy.php
- Apple IAP verification support in /api/billing.php
