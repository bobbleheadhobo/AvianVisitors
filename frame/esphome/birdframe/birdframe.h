// Helpers for the AvianVisitors ESPHome frame (common.yaml).
#pragma once

#include <cstdio>
#include <string>

#include "driver/gpio.h"
#include "driver/rtc_io.h"
#include "esp_sleep.h"
#include "esp_system.h"
#include "esphome/components/display/display.h"

namespace birdframe {

// EE04 user keys (active low), named as printed on the board. Seeed's wiki
// numbers them KEY0-KEY2.
static const gpio_num_t KEY_REFRESH = GPIO_NUM_2;  // KEY1
static const gpio_num_t KEY_NAMES = GPIO_NUM_3;    // KEY2
static const gpio_num_t KEY_AWAKE = GPIO_NUM_5;    // KEY3

enum WakeKey { WAKE_NONE = 0, WAKE_REFRESH = 1, WAKE_NAMES = 2, WAKE_AWAKE = 3 };

// Which key woke the board from deep sleep, if any.
inline int wake_key() {
  if (esp_sleep_get_wakeup_cause() != ESP_SLEEP_WAKEUP_EXT1)
    return WAKE_NONE;
  uint64_t mask = esp_sleep_get_ext1_wakeup_status();
  if (mask & (1ULL << KEY_REFRESH))
    return WAKE_REFRESH;
  if (mask & (1ULL << KEY_NAMES))
    return WAKE_NAMES;
  if (mask & (1ULL << KEY_AWAKE))
    return WAKE_AWAKE;
  return WAKE_NONE;
}

// Why this boot happened, for Home Assistant's Wake cause: the timer, a key,
// or a reset, with the raw ext1 pin mask so a key that wakes the board but
// is not recognised can be told apart from one that never woke it.
inline std::string wake_description() {
  char text[64];
  switch (esp_sleep_get_wakeup_cause()) {
    case ESP_SLEEP_WAKEUP_TIMER:
      return "Timer";
    case ESP_SLEEP_WAKEUP_EXT1: {
      uint64_t mask = esp_sleep_get_ext1_wakeup_status();
      const char *key = wake_key() == WAKE_REFRESH ? "KEY1" : wake_key() == WAKE_NAMES ? "KEY2"
                        : wake_key() == WAKE_AWAKE ? "KEY3" : "Unknown key";
      snprintf(text, sizeof(text), "%s (pins 0x%llx)", key, (unsigned long long) mask);
      return text;
    }
    case ESP_SLEEP_WAKEUP_UNDEFINED: {
      static const char *const reasons[] = {"unknown", "power on", "external", "software",
                                            "panic", "interrupt watchdog", "task watchdog",
                                            "watchdog", "deep sleep", "brownout", "SDIO"};
      int r = (int) esp_reset_reason();
      snprintf(text, sizeof(text), "Reset: %s", r >= 0 && r < 11 ? reasons[r] : "other");
      return text;
    }
    default:
      snprintf(text, sizeof(text), "Other (%d)", (int) esp_sleep_get_wakeup_cause());
      return text;
  }
}

// The battery divider's enable (ADC_EN, active high, per Seeed's example).
static const gpio_num_t ADC_ENABLE = GPIO_NUM_6;

// Switch the battery divider on or off. Releases the deep-sleep hold first:
// a held pad ignores writes.
inline void adc_enable(bool on) {
  rtc_gpio_hold_dis(ADC_ENABLE);
  rtc_gpio_deinit(ADC_ENABLE);
  gpio_set_direction(ADC_ENABLE, GPIO_MODE_OUTPUT);
  gpio_set_level(ADC_ENABLE, on ? 1 : 0);
}

// Before deep sleep. ESPHome's ext1 wakeup does not set sleep pull-ups, so
// hold the keys high through sleep (they read as released until pressed);
// and hold ADC_EN low, so the battery divider can't conduct while asleep
// whatever the pad would otherwise float to.
inline void prepare_sleep() {
  for (gpio_num_t pin : {KEY_REFRESH, KEY_NAMES, KEY_AWAKE}) {
    rtc_gpio_pullup_en(pin);
    rtc_gpio_pulldown_dis(pin);
  }
  rtc_gpio_init(ADC_ENABLE);
  rtc_gpio_set_direction(ADC_ENABLE, RTC_GPIO_MODE_OUTPUT_ONLY);
  rtc_gpio_set_level(ADC_ENABLE, 0);
  rtc_gpio_hold_en(ADC_ENABLE);
  esp_sleep_pd_config(ESP_PD_DOMAIN_RTC_PERIPH, ESP_PD_OPTION_ON);
}

// A small empty-battery mark in the top-right corner (portrait coordinates).
inline void draw_low_battery(esphome::display::Display &it) {
  const int w = 30, h = 14, x = it.get_width() - w - 10, y = 8;
  it.filled_rectangle(x - 3, y - 3, w + 9, h + 6, esphome::Color(255, 255, 255));
  it.rectangle(x, y, w, h, esphome::Color(0, 0, 0));
  it.rectangle(x + 1, y + 1, w - 2, h - 2, esphome::Color(0, 0, 0));
  it.filled_rectangle(x + w, y + 4, 3, h - 8, esphome::Color(0, 0, 0));
  it.filled_rectangle(x + 4, y + 4, 4, h - 8, esphome::Color(255, 0, 0));
}

// While KEY3 holds the battery build awake: a tag along the bottom edge, so
// it shows through the glass that the frame is listening for an update, and
// until when.
inline void draw_awake(esphome::display::Display &it, esphome::display::BaseFont *font, const char *text) {
  const int cx = it.get_width() / 2, base = it.get_height() - 14;
  int x1, y1, w, h;
  it.get_text_bounds(cx, base, text, font, esphome::display::TextAlign::BOTTOM_CENTER, &x1, &y1, &w, &h);
  // White box with a red outline (3 px, so it reads through the glass).
  const int pad = 8, edge = 3;
  const int left = x1 - pad, top = y1 - pad, bw = w + 2 * pad, bh = h + 2 * pad;
  it.filled_rectangle(left, top, bw, bh, esphome::Color(255, 255, 255));
  for (int i = 0; i < edge; i++)
    it.rectangle(left + i, top + i, bw - 2 * i, bh - 2 * i, esphome::Color(255, 0, 0));
  it.print(cx, base, font, esphome::Color(0, 0, 0), esphome::display::TextAlign::BOTTOM_CENTER, text);
}

}  // namespace birdframe
