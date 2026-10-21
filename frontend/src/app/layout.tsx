import type { Metadata, Viewport } from "next";
import { GeistMono } from "geist/font/mono";
import { GeistSans } from "geist/font/sans";
import { Providers } from "@/components/providers";
import { SiteHeader } from "@/components/site-header";
import { Suspense } from "react";
import "./globals.css";


export const metadata: Metadata = {
  title: { default: "Bijuli Charge — find and book EV chargers", template: "%s · Bijuli Charge" },
  description: "Find EV charging stations near you, see live charger availability, and book a charging slot in advance.",
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#ffffff" },
    { media: "(prefers-color-scheme: dark)", color: "#151a17" },
  ],
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className={`${GeistSans.variable} ${GeistMono.variable} h-full antialiased`}>
      <body className="flex min-h-full flex-col font-sans">
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[2000] focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-brand-ink"
        >
          Skip to content
        </a>
        <Providers>
          <Suspense fallback={<div className="sticky top-0 z-50 h-16 border-b border-line bg-surface" />}>
            <SiteHeader />
          </Suspense>
          <main id="main" className="flex flex-1 flex-col">{children}</main>
        </Providers>
      </body>
    </html>
  );
}
