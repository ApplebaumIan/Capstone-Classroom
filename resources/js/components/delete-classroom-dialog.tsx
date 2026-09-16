import { Form } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy as destroyClassroom } from '@/routes/classrooms';

type Props = {
    classroom: {
        id: number;
        name: string;
    };
};

export default function DeleteClassroomDialog({ classroom }: Props) {
    const [confirmation, setConfirmation] = useState('');

    return (
        <Dialog
            onOpenChange={(open) => {
                if (!open) {
                    setConfirmation('');
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="destructive">
                    <Trash2 /> Delete classroom
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {classroom.name}?</DialogTitle>
                    <DialogDescription>
                        This permanently deletes the classroom, roster, teams,
                        and sync history from Capstone Classroom. GitHub
                        repositories and teams will remain unchanged.
                    </DialogDescription>
                </DialogHeader>
                <Form {...destroyClassroom.form(classroom.id)}>
                    {({ errors, processing }) => (
                        <div className="grid gap-4">
                            <div className="grid gap-2">
                                <Label
                                    htmlFor={`delete-classroom-${classroom.id}`}
                                >
                                    Type <strong>{classroom.name}</strong> to
                                    confirm
                                </Label>
                                <Input
                                    id={`delete-classroom-${classroom.id}`}
                                    name="confirmation"
                                    value={confirmation}
                                    onChange={(event) =>
                                        setConfirmation(event.target.value)
                                    }
                                    autoComplete="off"
                                />
                                <InputError message={errors.confirmation} />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={
                                        processing ||
                                        confirmation !== classroom.name
                                    }
                                >
                                    <Trash2 /> Delete classroom
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
