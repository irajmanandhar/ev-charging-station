"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Suspense } from "react";
import { cx } from "./ui";

type Item = { href: string; label: string; exact?: boolean };

export function SubNav({ items }: { items: Item[] }) {
  return (
    <Suspense fallback={<div className="h-[45px] border-b border-line bg-surface" />}>
      <SubNavInner items={items} />
    </Suspense>
  );
}

function SubNavInner({ items }: { items: Item[] }) {
  const pathname = usePathname();
  return (
    <div className="border-b border-line bg-surface">
      <nav className="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 sm:px-6" aria-label="Section">
        {items.map((i) => {
          const active = i.exact ? pathname === i.href : pathname.startsWith(i.href);
          return (
            <Link
              key={i.href}
              href={i.href}
              aria-current={active ? "page" : undefined}
              className={cx(
                "-mb-px border-b-2 px-3 py-3 text-sm font-medium whitespace-nowrap",
                active ? "border-brand text-ink" : "border-transparent text-ink-2 hover:text-ink",
              )}
            >
              {i.label}
            </Link>
          );
        })}
      </nav>
    </div>
  );
}
