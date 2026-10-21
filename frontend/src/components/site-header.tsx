"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import { useAuth } from "@/lib/auth";
import { LanguageSwitcher, useT } from "@/lib/i18n";
import { ButtonLink, cx } from "./ui";

function Logo() {
  return (
    <Link href="/" className="flex items-center gap-2 font-semibold tracking-tight text-ink">
      <span aria-hidden className="grid size-8 place-items-center rounded-lg bg-brand text-brand-ink">
        <svg viewBox="0 0 24 24" className="size-5" fill="currentColor">
          <path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z" />
        </svg>
      </span>
      <span>
        Bijuli<span className="text-brand"> Charge</span>
      </span>
    </Link>
  );
}

export function SiteHeader() {
  const { user, loading, signOut } = useAuth();
  const pathname = usePathname();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const t = useT();

  const links = [
    { href: "/", label: t("nav.find"), show: true },
    { href: "/bookings", label: t("nav.bookings"), show: !!user },
    { href: "/operator", label: t("nav.operator"), show: user?.role === "operator" || user?.role === "admin" },
    { href: "/admin", label: t("nav.admin"), show: user?.role === "admin" },
  ].filter((l) => l.show);

  const isActive = (href: string) => (href === "/" ? pathname === "/" || pathname.startsWith("/stations") : pathname.startsWith(href));

  return (
    <header className="sticky top-0 z-50 border-b border-line bg-surface/90 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6">
        <Logo />
        <nav className="hidden flex-1 items-center gap-1 md:flex" aria-label="Main">
          {links.map((l) => (
            <Link
              key={l.href}
              href={l.href}
              className={cx(
                "rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                isActive(l.href) ? "bg-brand-soft text-ink" : "text-ink-2 hover:bg-surface-2 hover:text-ink",
              )}
            >
              {l.label}
            </Link>
          ))}
        </nav>
        <div className="ml-auto hidden items-center gap-3 md:flex">
          <LanguageSwitcher />
          {loading ? null : user ? (
            <>
              <span className="text-sm text-ink-2">
                {user.name}
                {user.role !== "driver" && <span className="ml-2 rounded bg-surface-2 px-1.5 py-0.5 text-xs capitalize">{user.role}</span>}
              </span>
              <button
                onClick={async () => {
                  await signOut();
                  router.push("/");
                }}
                className="rounded-lg px-3 py-2 text-sm font-medium text-ink-2 hover:bg-surface-2 hover:text-ink"
              >
                {t("auth.signOut")}
              </button>
            </>
          ) : (
            <>
              <ButtonLink href="/login" variant="ghost">
                {t("auth.signIn")}
              </ButtonLink>
              <ButtonLink href="/register">{t("auth.createAccount")}</ButtonLink>
            </>
          )}
        </div>
        <button
          className="ml-auto grid size-10 place-items-center rounded-lg text-ink hover:bg-surface-2 md:hidden"
          aria-label={open ? "Close menu" : "Open menu"}
          aria-expanded={open}
          onClick={() => setOpen((o) => !o)}
        >
          <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            {open ? <path d="M6 6l12 12M18 6 6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
          </svg>
        </button>
      </div>
      {open && (
        <div className="border-t border-line px-4 py-3 md:hidden">
          <nav className="flex flex-col gap-1" aria-label="Mobile">
            {links.map((l) => (
              <Link
                key={l.href}
                href={l.href}
                onClick={() => setOpen(false)}
                className={cx("rounded-lg px-3 py-2.5 text-sm font-medium", isActive(l.href) ? "bg-brand-soft" : "text-ink-2")}
              >
                {l.label}
              </Link>
            ))}
            <div className="mt-2 border-t border-line pt-3">
              <div className="mb-2 px-3">
                <LanguageSwitcher />
              </div>
              {user ? (
                <button
                  onClick={async () => {
                    setOpen(false);
                    await signOut();
                    router.push("/");
                  }}
                  className="w-full rounded-lg px-3 py-2.5 text-left text-sm font-medium text-ink-2"
                >
                  {t("auth.signOut")} ({user.name})
                </button>
              ) : (
                <div className="flex gap-2">
                  <ButtonLink href="/login" variant="secondary" className="flex-1" onClick={() => setOpen(false)}>
                    {t("auth.signIn")}
                  </ButtonLink>
                  <ButtonLink href="/register" className="flex-1" onClick={() => setOpen(false)}>
                    {t("auth.createAccount")}
                  </ButtonLink>
                </div>
              )}
            </div>
          </nav>
        </div>
      )}
    </header>
  );
}
