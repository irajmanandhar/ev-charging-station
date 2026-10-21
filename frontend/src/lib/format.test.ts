import { describe, expect, it } from "vitest";
import { addDays, duration, hours, kwh, money, percent, time, ymd } from "./format";

describe("format", () => {
  it("formats money in NPR with Indian digit grouping", () => {
    expect(money(1020)).toBe("NPR 1,020");
    expect(money(123456)).toBe("NPR 1,23,456");
    expect(money(null)).toBe("—");
  });

  it("formats energy and percentages", () => {
    expect(kwh(51)).toBe("51 kWh");
    expect(kwh(undefined)).toBe("—");
    expect(percent(0.256)).toBe("25.6%");
  });

  it("formats durations", () => {
    expect(duration(45)).toBe("45 min");
    expect(duration(60)).toBe("1 h");
    expect(duration(95)).toBe("1 h 35 min");
  });

  it("shows times in the station's time zone (Kathmandu is UTC+5:45)", () => {
    expect(time("2026-10-21T04:15:00Z", "Asia/Kathmandu")).toBe("10:00");
    expect(time("2026-10-21T04:15:00Z", "UTC")).toBe("04:15");
  });

  it("does calendar maths on YYYY-MM-DD strings", () => {
    expect(addDays("2026-10-31", 1)).toBe("2026-11-01");
    expect(addDays("2026-03-01", -1)).toBe("2026-02-28");
    // 20:00 UTC is already the next day in Kathmandu
    expect(ymd(new Date("2026-10-21T20:00:00Z"), "Asia/Kathmandu")).toBe("2026-10-22");
  });

  it("describes opening hours", () => {
    expect(hours({ is_24_hours: true, opens_at: null, closes_at: null })).toBe("Open 24 hours");
    expect(hours({ is_24_hours: false, opens_at: "07:00", closes_at: "22:00" })).toBe("07:00–22:00");
  });
});
