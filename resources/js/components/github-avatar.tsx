import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

export default function GitHubAvatar({
    name,
    avatarUrl,
    className,
}: {
    name: string;
    avatarUrl: string | null;
    className?: string;
}) {
    const getInitials = useInitials();

    return (
        <Avatar className={cn('size-9', className)}>
            <AvatarImage
                src={avatarUrl ?? undefined}
                alt={`${name} GitHub profile`}
            />
            <AvatarFallback>{getInitials(name)}</AvatarFallback>
        </Avatar>
    );
}
