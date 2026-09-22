import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Niwadu",
  robots: { index: false, follow: false },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
