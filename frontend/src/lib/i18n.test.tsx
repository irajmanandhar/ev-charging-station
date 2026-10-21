import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { LanguageSwitcher, LocaleProvider, useT } from "./i18n";

function Probe() {
  const t = useT();
  return <p>{t("nav.bookings")}</p>;
}

function renderApp() {
  return render(
    <LocaleProvider>
      <LanguageSwitcher />
      <Probe />
    </LocaleProvider>,
  );
}

describe("i18n", () => {
  it("defaults to English and switches to Nepali, remembering the choice", async () => {
    const { unmount } = renderApp();
    expect(screen.getByText("My bookings")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "नेपाली" }));
    expect(screen.getByText("मेरा बुकिङहरू")).toBeInTheDocument();
    expect(document.documentElement.lang).toBe("ne");
    expect(window.localStorage.getItem("ev.locale")).toBe("ne");

    unmount();
    renderApp();
    expect(await screen.findByText("मेरा बुकिङहरू")).toBeInTheDocument();
  });

  it("marks the active language with aria-pressed", async () => {
    renderApp();
    expect(screen.getByRole("button", { name: "EN" })).toHaveAttribute("aria-pressed", "true");
    await userEvent.click(screen.getByRole("button", { name: "नेपाली" }));
    expect(screen.getByRole("button", { name: "नेपाली" })).toHaveAttribute("aria-pressed", "true");
  });
});
