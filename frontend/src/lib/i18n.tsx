"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

export type Locale = "en" | "ne";

const en = {
  "nav.find": "Find a charger",
  "nav.bookings": "My bookings",
  "nav.operator": "Operator",
  "nav.admin": "Admin",
  "auth.signIn": "Sign in",
  "auth.createAccount": "Create account",
  "auth.signOut": "Sign out",
  "lang.label": "Language",
  "finder.title": "Find a charger",
  "finder.search": "Search by name, area or city",
  "bookings.title": "My bookings",
  "bookings.subtitle": "Upcoming reservations, live charging and your history.",
  "bookings.book": "Book a charger",
  "bookings.sessions": "Sessions",
  "bookings.energy": "Energy charged",
  "bookings.spent": "Total spent",
  "bookings.chargingNow": "Charging now",
  "bookings.upcoming": "Upcoming",
  "bookings.history": "History",
  "bookings.checkIn": "Check in & start",
  "bookings.cancel": "Cancel booking",
  "bookings.stop": "Stop charging",
  "bookings.pay": "Pay online",
  "bookings.paid": "Paid",
  "operator.export": "Export CSV",
} as const;

export type MessageKey = keyof typeof en;

const ne: Record<MessageKey, string> = {
  "nav.find": "चार्जर खोज्नुहोस्",
  "nav.bookings": "मेरा बुकिङहरू",
  "nav.operator": "सञ्चालक",
  "nav.admin": "एडमिन",
  "auth.signIn": "साइन इन",
  "auth.createAccount": "खाता खोल्नुहोस्",
  "auth.signOut": "साइन आउट",
  "lang.label": "भाषा",
  "finder.title": "चार्जर खोज्नुहोस्",
  "finder.search": "नाम, क्षेत्र वा सहरबाट खोज्नुहोस्",
  "bookings.title": "मेरा बुकिङहरू",
  "bookings.subtitle": "आगामी बुकिङ, चलिरहेको चार्जिङ र तपाईंको इतिहास।",
  "bookings.book": "चार्जर बुक गर्नुहोस्",
  "bookings.sessions": "सत्रहरू",
  "bookings.energy": "चार्ज गरिएको ऊर्जा",
  "bookings.spent": "कुल खर्च",
  "bookings.chargingNow": "अहिले चार्ज हुँदै",
  "bookings.upcoming": "आगामी",
  "bookings.history": "इतिहास",
  "bookings.checkIn": "चेक इन र सुरु गर्नुहोस्",
  "bookings.cancel": "बुकिङ रद्द गर्नुहोस्",
  "bookings.stop": "चार्जिङ रोक्नुहोस्",
  "bookings.pay": "अनलाइन भुक्तानी",
  "bookings.paid": "भुक्तानी भयो",
  "operator.export": "CSV निर्यात",
};

const dictionaries: Record<Locale, Record<MessageKey, string>> = { en, ne };
const KEY = "ev.locale";

type Ctx = { locale: Locale; setLocale: (l: Locale) => void };
const LocaleContext = createContext<Ctx>({ locale: "en", setLocale: () => {} });

export function LocaleProvider({ children }: { children: React.ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>("en");

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(KEY);
      // Restoring the saved choice after hydration (the server always renders English).
      // eslint-disable-next-line react-hooks/set-state-in-effect
      if (saved === "ne") setLocaleState("ne");
    } catch {
      /* storage unavailable */
    }
  }, []);

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const setLocale = useCallback((l: Locale) => {
    setLocaleState(l);
    try {
      window.localStorage.setItem(KEY, l);
    } catch {
      /* storage unavailable */
    }
  }, []);

  const value = useMemo(() => ({ locale, setLocale }), [locale, setLocale]);
  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>;
}

/** Translate by key; falls back to English if a key is missing. */
export function useT() {
  const { locale } = useContext(LocaleContext);
  return useCallback((key: MessageKey) => dictionaries[locale][key] ?? en[key], [locale]);
}

export function LanguageSwitcher() {
  const { locale, setLocale } = useContext(LocaleContext);
  const t = useT();
  return (
    <div role="group" aria-label={t("lang.label")} className="inline-flex overflow-hidden rounded-lg border border-line text-xs font-medium">
      {(["en", "ne"] as const).map((l) => (
        <button
          key={l}
          type="button"
          onClick={() => setLocale(l)}
          aria-pressed={locale === l}
          lang={l}
          className={`px-2.5 py-1.5 ${locale === l ? "bg-brand-soft text-ink" : "text-ink-2 hover:bg-surface-2"}`}
        >
          {l === "en" ? "EN" : "नेपाली"}
        </button>
      ))}
    </div>
  );
}
