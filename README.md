# Weather Info Widget

**Contributors:** Muzammil Hussain  
**Tags:** weather, OpenWeather, widget, WordPress, cache, AES-256  
**Requires at least:** 5.0  
**Tested up to:** 6.3  
**Stable tag:** 1.0  
**Requires PHP:** 7.2  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress widget plugin that displays the current weather for a specified city using the OpenWeather API. Features include:

- **Encrypted API key storage** (AES-256-CBC with WordPress salts, masked on entry).
- **Settings page** under “Settings → Weather Info API” to enter or update your OpenWeather API key.
- **Widget configuration** with city name, temperature unit (Celsius/Fahrenheit), and three styling modes (Minimal, Standard, Advanced).
- **Caching** (1-hour transient cache per city/unit combination).
- **Cache invalidation** when the API key or city/unit changes.
- **Hourly WP-Cron refresh** of weather data for the most recently saved city.

---

## Table of Contents

1. [Installation](#installation)
2. [Setup & Configuration](#setup--configuration)
   - [Add Your OpenWeather API Key](#add-your-openweather-api-key)
   - [Configure and Place the Widget](#configure-and-place-the-widget)
3. [Features](#features)
4. [Widget Options](#widget-options)
5. [Styling](#styling)
6. [Caching & Cron](#caching--cron)
7. [Uninstallation](#uninstallation)
8. [Frequently Asked Questions](#frequently-asked-questions)
9. [Support](#support)
10. [Changelog](#changelog)

---

## Installation

1. **Download the ZIP**

   - Download `weather-info-widget.zip` from the plugin repository or your file manager.

2. **Install via Dashboard**

   1. In your WordPress admin, go to **Plugins → Add New**.
   2. Click **Upload Plugin**, choose the ZIP file, and click **Install Now**.
   3. After installation, click **Activate**.

   _Alternatively:_

   1. Unzip `weather-info-widget.zip`.
   2. Upload the folder `weather-info-widget/` to `/wp-content/plugins/`.
   3. Activate the plugin from **Plugins** in your WordPress admin.

3. **Verify Activation**
   - After activation, you should see a new menu entry under **Settings → Weather Info API**.

---

## Setup & Configuration

### Add Your OpenWeather API Key

1. In the WordPress admin sidebar, navigate to **Settings → Weather Info API**.
2. You will see a password-style (masked) input labeled **OpenWeather API Key**.
   - If no key is stored, the placeholder will be empty.
   - If a key is already stored, you will see sixteen “•” characters as a placeholder.
3. Paste your OpenWeatherMap API key into the field and click **Save API Key**.
   - The key is encrypted using AES-256-CBC with a passphrase derived from `SECURE_AUTH_KEY . NONCE_KEY`.
   - On save, all cached weather transients (keys prefixed with `wiw_weather_data_`) are deleted.
4. If you later want to replace or remove the key, leave the field blank to retain the current key (you’ll see an informational notice).

_Behavior after saving:_

- **Success (new key entered):** A green notice appears:
  > “API key saved, encrypted, and cache cleared.”
- **No key entered (blank):** A blue notice appears:
  > “No new key entered; existing API key remains unchanged.”

### Configure and Place the Widget

1. In the WordPress admin, go to **Appearance → Widgets** (or **Appearance → Customize → Widgets**).
2. Locate **Weather Info Widget** in the list of available widgets.
3. Drag it into your desired widget area (e.g., Sidebar, Footer).
4. Click the arrow on the widget to expand its settings.

---

## Features

- **Encrypted & Masked API Key**

  - Uses `SECURE_AUTH_KEY . NONCE_KEY` as a passphrase, a 16-byte IV derived via `hash('sha256', $passphrase, true)`, and stores the base64-encoded cipher text in `wiw_encrypted_api_key`.
  - The password field is masked; leaving it blank retains the stored key.

- **Transient Cache (1 Hour)**

  - Caches each city/unit combination under transient key `wiw_weather_data_{md5(strtolower(city) . '_' . unit)}`.
  - If cached data exists, no API request is made.
  - Whenever you change the API key (on the Settings page), all `wiw_weather_data_` transients are deleted.
  - Whenever you change a widget’s **City** or **Unit**, the old transient is deleted automatically.

- **WP-Cron: Hourly Update**

  - Each time a widget with a nonempty city is saved or updated, the plugin schedules an hourly cron event `wiw_hourly_update`.
  - The cron callback `wiw_do_hourly_update` retrieves the stored city (from `get_option('wiw_cron_city')`), decrypts the API key, and calls `wiw_fetch_weather_data()` to refresh the cache.
  - On deactivation, the cron event is unscheduled and the stored city is removed.

- **Three Display Modes**
  1. **Minimal (Theme Styling)**
     - Displays city name, weather description, and current temperature (with unit).
  2. **Standard (Basic Styling)**
     - Minimal + “Feels like” + humidity + wind speed + pressure.
     - Wrapped in a light gray card (`.weather-info-widget-standard`).
  3. **Advanced (Weather Card)**
     - A modern “MacBook-style” card with blue gradient background and white text.
     - Shows city name, large weather icon, description, temperature, feels-like, and a 2×2 grid of details (min/max, humidity, pressure, wind, visibility).
     - Two layout options: **Vertical** (default) or **Horizontal** (side-by-side on wider screens).

---

## Widget Options

1. **Title (optional)**

   - Customizable widget title. Defaults to “Weather” if left blank.

2. **City Name (required)**

   - Type the city exactly as recognized by OpenWeatherMap (e.g., “London,UK” or “New York,US”).
   - If left blank, the widget shows:
     > “Please set a city in widget settings.”

3. **Temperature Unit**

   - **Celsius (°C)** (alias `metric`)
   - **Fahrenheit (°F)** (alias `imperial`)

4. **Display Style**

   - **Minimal** (inherits your theme’s default styling)
   - **Standard** (basic styled card)
   - **Advanced** (weather card with gradient and details grid)

5. **Card Layout** (only visible when **Advanced** is selected)
   - **Vertical** (stacked elements)
   - **Horizontal** (info placed side-by-side on desktop/responsive collapse on smaller screens)

_Any change to “City Name” or “Temperature Unit” will automatically delete the old transient so that fresh data is fetched._

---

## Styling

All widget styles are contained in `style.css` (loaded via `wiw_enqueue_styles`). If you prefer to override or extend styles:

1. Copy `.weather-info-widget-standard { … }` and/or `.weather-info-widget-advanced { … }` selectors into your child theme’s `style.css`.
2. Adjust fonts, colors, margins as needed.
3. For `.weather-info-widget-advanced`, note there are media queries for max-width 768px and 480px, so horizontal layout stacks vertically on tablets and phones.

---

## Caching & Cron

- **Transient Key Format**
  ```php
  $transient_key = 'wiw_weather_data_' . md5( strtolower($city) . '_' . $unit );
  ```
