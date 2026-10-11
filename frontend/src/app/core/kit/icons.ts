import { IconSet } from '@anotoki/lib/ui/config';
import { KitIconName } from '@anotoki/lib/ui/icon';
import {
  iconAppWindow,
  iconArrowRight,
  iconBan,
  iconClock,
  iconDashboard,
  iconDatabase,
  iconDice,
  iconDownload,
  iconGlobe,
  iconGrid,
  iconHistory,
  iconKey,
  iconLanguages,
  iconLaptop,
  iconLink,
  iconLock,
  iconMail,
  iconPencil,
  iconSend,
  iconShieldCheck,
  iconSliders,
  iconSmartphone,
  iconTerminal,
  iconTrash,
  iconUserPlus,
  iconUsers,
} from '@anotoki/lib/ui/icons';

/**
 * The icons the IAM's own templates name, beside the kit's own (sun, moon,
 * shield, check, x...): registered with the kit (core/kit/kit.config.ts), so
 * that `<anotoki-icon name="users">` draws. They are data - the family's
 * paths from @anotoki/lib/ui/icons - and only those named here are in the
 * build.
 */
export const IAM_ICONS = {
  appWindow: iconAppWindow,
  arrowRight: iconArrowRight,
  ban: iconBan,
  clock: iconClock,
  dashboard: iconDashboard,
  database: iconDatabase,
  dice: iconDice,
  download: iconDownload,
  globe: iconGlobe,
  grid: iconGrid,
  history: iconHistory,
  key: iconKey,
  languages: iconLanguages,
  laptop: iconLaptop,
  link: iconLink,
  lock: iconLock,
  mail: iconMail,
  pencil: iconPencil,
  send: iconSend,
  shieldCheck: iconShieldCheck,
  sliders: iconSliders,
  smartphone: iconSmartphone,
  terminal: iconTerminal,
  trash: iconTrash,
  userPlus: iconUserPlus,
  users: iconUsers,
} satisfies IconSet;

/**
 * An icon the IAM can draw: the kit's own or one of the above. `<anotoki-icon>`
 * takes any string (an unknown one draws nothing); the IAM's own lists of
 * links and tiles are typed with this, so a misspelt icon is a compile error.
 */
export type IconName = KitIconName | keyof typeof IAM_ICONS;
