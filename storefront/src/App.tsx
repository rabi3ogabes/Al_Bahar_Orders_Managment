import { lazy, Suspense } from 'react';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError } from './lib/api';
import { AuthProvider } from './lib/auth';
import { BranchProvider } from './lib/branch';
import { CartProvider } from './lib/cart';
import { I18nProvider } from './lib/i18n';
import { ThemeProvider } from './lib/theme';
import { ToastProvider } from './lib/toast';
import { WishlistProvider } from './lib/wishlist';
import { AuthModalProvider, useAuthModal } from './components/AuthModal';
import { Layout } from './components/Layout';
import { Spinner } from './components/ui';
import Home from './pages/Home';
import { NotFound, CmsPageView, Showrooms } from './pages/Misc';

const Shop = lazy(() => import('./pages/Shop'));
const Product = lazy(() => import('./pages/Product'));
const Cart = lazy(() => import('./pages/Cart'));
const Checkout = lazy(() => import('./pages/Checkout'));
const Order = lazy(() => import('./pages/Order'));
const Account = lazy(() => import('./pages/Account').then((m) => ({ default: m.AccountLayout })));
const acc = <K extends string>(k: K) => lazy(() => import('./pages/Account').then((m) => ({ default: (m as any)[k] })));
const Overview = acc('Overview'), Orders = acc('Orders'), Addresses = acc('Addresses'), Wishlist = acc('Wishlist'), Loyalty = acc('LoyaltyPage'), Notifications = acc('Notifications'), Support = acc('Support'), Profile = acc('Profile'), MyAuctions = acc('MyAuctions');
const AuctionList = lazy(() => import('./pages/Auctions').then((m) => ({ default: m.AuctionList })));
const AuctionPage = lazy(() => import('./pages/Auctions').then((m) => ({ default: m.AuctionPage })));
const LotPage = lazy(() => import('./pages/Auctions').then((m) => ({ default: m.LotPage })));

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000, refetchOnWindowFocus: false,
      retry: (n, e) => !(e instanceof ApiError && e.status >= 400 && e.status < 500) && n < 2,
    },
  },
});

function Wishes({ children }: { children: React.ReactNode }) {
  const modal = useAuthModal();
  return <WishlistProvider onNeedAuth={() => modal.open()}>{children}</WishlistProvider>;
}

const Fallback = <div className="flex min-h-[50vh] items-center justify-center"><Spinner size={28} /></div>;

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <I18nProvider>
          <ToastProvider>
            <AuthProvider>
              <BranchProvider>
                <AuthModalProvider>
                  <CartProvider>
                    <Wishes>
                      <BrowserRouter>
                        <Suspense fallback={Fallback}>
                          <Routes>
                            <Route element={<Layout />}>
                              <Route index element={<Home />} />
                              <Route path="shop" element={<Shop />} />
                              <Route path="products/:slug" element={<Product />} />
                              <Route path="cart" element={<Cart />} />
                              <Route path="checkout" element={<Checkout />} />
                              <Route path="orders/:id" element={<Order />} />
                              <Route path="auctions" element={<AuctionList />} />
                              <Route path="auctions/lots/:lotId" element={<LotPage />} />
                              <Route path="auctions/:id" element={<AuctionPage />} />
                              <Route path="showrooms" element={<Showrooms />} />
                              <Route path="pages/:slug" element={<CmsPageView />} />
                              <Route path="account" element={<Account />}>
                                <Route index element={<Overview />} />
                                <Route path="orders" element={<Orders />} />
                                <Route path="addresses" element={<Addresses />} />
                                <Route path="wishlist" element={<Wishlist />} />
                                <Route path="auctions" element={<MyAuctions />} />
                                <Route path="loyalty" element={<Loyalty />} />
                                <Route path="notifications" element={<Notifications />} />
                                <Route path="support" element={<Support />} />
                                <Route path="profile" element={<Profile />} />
                              </Route>
                              <Route path="*" element={<NotFound />} />
                            </Route>
                          </Routes>
                        </Suspense>
                      </BrowserRouter>
                    </Wishes>
                  </CartProvider>
                </AuthModalProvider>
              </BranchProvider>
            </AuthProvider>
          </ToastProvider>
        </I18nProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
}
