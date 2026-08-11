import { IconName } from '../../../../shared/icons';

export interface FreightType {
  slug: string;
  label: string;
  blurb: string;
  /** Fallback glyph, shown while the photo loads and if `image` is unset. */
  icon: IconName;
  /** Card photo in web/public/. Omit to fall back to the icon plate. */
  image?: string;
}

/**
 * Shared by the "Freight We Handle" cards and the quote-search freight picker
 * so the two can never drift apart.
 */
export const FREIGHT_TYPES: FreightType[] = [
  {
    slug: 'heavy-haulage',
    label: 'Heavy Haulage',
    blurb: 'Oversize & heavy machinery transport',
    icon: 'excavator',
    image: '/heavy-haulage.webp',
  },
  {
    slug: 'general-freight',
    label: 'General Freight',
    blurb: 'Pallets, cartons & commercial freight',
    icon: 'boxes',
    image: '/general-freight.webp',
  },
  {
    slug: 'container-transport',
    label: 'Container Transport',
    blurb: '20ft, 40ft & specialist containers',
    icon: 'container',
    image: '/container-transport.webp',
  },
  {
    slug: 'machinery-transport',
    label: 'Machinery Transport',
    blurb: 'Excavators, dozers & industrial equipment',
    icon: 'excavator',
    image: '/machinery-transport.webp',
  },
  {
    slug: 'livestock-transport',
    label: 'Livestock Transport',
    blurb: 'Cattle, sheep & livestock carriers',
    icon: 'cow',
    image: '/livestock-transport.webp',
  },
  {
    slug: 'boat-transport',
    label: 'Boat Transport',
    blurb: 'Yachts, boats & marine transport',
    icon: 'boat',
    image: '/boat-transport.webp',
  },
  {
    slug: 'truck-trailer-transport',
    label: 'Truck & Trailer Transport',
    blurb: 'Rigid, prime mover & trailer transport',
    icon: 'truck',
    image: '/truck-trailer-transport.webp',
  },
  {
    slug: 'grain-hay-transport',
    label: 'Grain & Hay Transport',
    blurb: 'Bulk grain, hay & agricultural freight',
    icon: 'wheat',
    image: '/grain-hay-transport.webp',
  },
  {
    slug: 'bulk-tipper-transport',
    label: 'Bulk Tipper Transport',
    blurb: 'Sand, soil, gravel & bulk materials',
    icon: 'truck-fast',
    image: '/bulk-tipper-transport.webp',
  },
  {
    slug: 'liquid-tanker-transport',
    label: 'Liquid Tanker Transport',
    blurb: 'Fuel, water, chemicals & liquid transport',
    icon: 'droplet',
    image: '/liquid-tanker-transport.webp',
  },
  {
    slug: 'portable-building-transport',
    label: 'Portable Building Transport',
    blurb: 'Sheds, cabins & modular buildings',
    icon: 'home',
    image: '/portable-building-transport.webp',
  },
  {
    slug: 'palletised-freight',
    label: 'Palletised Freight',
    blurb: 'Pallets, skids & packaged goods',
    icon: 'boxes',
    image: '/palletised-freight.webp',
  },
];
