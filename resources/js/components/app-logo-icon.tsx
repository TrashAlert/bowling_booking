import type { SVGAttributes } from 'react';

/**
 * The mark shown where the business has no logo of its own: a bowling ball
 * with its three finger holes. It takes the colour of the text around it.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M20 2a18 18 0 1 0 0 36a18 18 0 1 0 0-36ZM14.5 10a3 3 0 1 0 0 6a3 3 0 1 0 0-6ZM22.5 9a3 3 0 1 0 0 6a3 3 0 1 0 0-6ZM19 16.5a3 3 0 1 0 0 6a3 3 0 1 0 0-6Z"
            />
        </svg>
    );
}
