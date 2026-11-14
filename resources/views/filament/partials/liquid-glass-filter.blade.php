{{--
    The lens used by liquid glass bars. A displacement map that is neutral in the middle and ramps at the edges, so
    what is behind the glass stays clear in the centre and bends near the rim, like a thick piece of glass.
    Red ramps left to right, green top to bottom; 128 (#80) means "no shift".
--}}
<svg width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">
    <filter id="sinag-liquid-glass" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
        <feImage result="lens" x="0" y="0" width="100%" height="100%" preserveAspectRatio="none"
            href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cdefs%3E%3ClinearGradient id='r'%3E%3Cstop offset='0' stop-color='%23000'/%3E%3Cstop offset='.12' stop-color='%23800000'/%3E%3Cstop offset='.88' stop-color='%23800000'/%3E%3Cstop offset='1' stop-color='%23f00'/%3E%3C/linearGradient%3E%3ClinearGradient id='g' x2='0' y2='1'%3E%3Cstop offset='0' stop-color='%23000'/%3E%3Cstop offset='.32' stop-color='%23008000'/%3E%3Cstop offset='.68' stop-color='%23008000'/%3E%3Cstop offset='1' stop-color='%230f0'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='100' height='100' fill='url(%23r)'/%3E%3Crect width='100' height='100' fill='url(%23g)' style='mix-blend-mode:screen'/%3E%3C/svg%3E" />
        <feDisplacementMap in="SourceGraphic" in2="lens" scale="40" xChannelSelector="R" yChannelSelector="G" />
    </filter>
</svg>
